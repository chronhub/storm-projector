<?php

declare(strict_types=1);

namespace Storm\Projector\Run;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\TransactionIsolationLevel;
use JsonException;
use Random\RandomException;
use Storm\Contracts\Chronicler\EventTypeMapper;
use Storm\Contracts\Projector\ProjectionCommitListener;
use Storm\EventLinks\DerivedStreamRevision;
use Storm\Projector\Exception\InvalidRunOptions;
use Storm\Projector\Exception\LeaseLost;
use Storm\Projector\Exception\ProjectionHomeMismatch;
use Storm\Projector\Exception\ProjectionOutOfDate;
use Storm\Projector\Exception\StaleMigratedContent;
use Storm\Projector\Exception\UnknownProjection;
use Storm\Projector\Exception\UnsupportedProjection;
use Storm\Projector\Registry\ProjectionRegistry;
use Storm\Projector\Run\Stage\ReadBatch;
use Storm\Projector\Store\ProjectionStatus;
use Storm\Projector\Telemetry\BatchContext;
use Storm\Projector\Telemetry\ListenerFailureContext;
use Storm\Projector\Telemetry\ProjectorObservability;
use Storm\Projector\Telemetry\RunContext;
use Throwable;

/**
 * Drives a persistent projection: claims the lease, runs batches through the per-batch pipeline, each in
 * a transaction retried on a transient deadlock, advances the checkpoint, and loops per the `RunOptions`
 * mode, once/drain/daemon. Releases the lease on exit.
 *
 * Reads are bounded by the safe head, applied in `ReadBatch`, so a projection never skips an in-flight
 * position; a derived-stream consumer is capped further by its producer's link head.
 *
 * @see ReadBatch
 */
final readonly class ProjectionRunner
{
    private const int MAX_TX_ATTEMPTS = 3;

    private ProjectionRunPreflight $preflight;

    public function __construct(
        private ProjectionRegistry $registry,
        private ProjectionLanes $lanes,
        private ProjectorObservability $obs,
        /**
         * Required, never optional: it backs the derived-rebuild GATE, and a gate that a manual wiring
         * can leave out is not a gate. Contrast the safe-head advancer, optional because it only makes
         * an already-correct watermark cheaper. A filtered-only runner still has to pass one; it costs
         * the connection it already holds.
         */
        private DerivedStreamRevision $derivedRevision,
        // REQUIRED, no identity default: the mapper decides which STORED TYPES a declared event
        // class matches, so an omitted argument silently reads different rows than production;
        // a manual composition states its identity, `new IdentityEventTypeMapper` spelled out
        private EventTypeMapper $eventTypeMapper,
        private ProjectionCommitListener $commits = new NullProjectionCommitListener,
    ) {
        // composed here, never injected: the gate sequence is a carve of this runner's own deps, and
        // an injectable preflight would be a second entry point wiring could route around
        $this->preflight = new ProjectionRunPreflight($this->registry, $this->lanes, $this->derivedRevision, $this->eventTypeMapper);
    }

    /**
     * @param  (callable(): bool)|null  $shouldStop  polled between batches; the console wires it to a
     *                                               SIGTERM/SIGINT handler for a graceful daemon stop
     * @return RunOutcome whether the run began, and why it did not: a stand-down is not a failure, and
     *                    it is not a finished catch-up either
     *
     * @throws UnknownProjection when no projection is registered under `$name`
     * @throws UnsupportedProjection when `$name` is a QueryProjection rather than a persistent one, or
     *                               declares neither or both selections
     * @throws ProjectionHomeMismatch when `$name`'s state sits on a database home its current kind does
     *                                not own, the trace of a kind change shipped without a state-home
     *                                migration
     * @throws ProjectionOutOfDate when the declared generation differs from the built-under one without
     *                             `allowStale`, or the declared selection drifted from the one the kept
     *                             checkpoint was built under, which is never bypassable; also mid-run,
     *                             re-checked at every cycle boundary, when the consumed derived stream is
     *                             rebuilt under a running consumer, the projection then being marked Failed
     * @throws StaleMigratedContent when a MigratedReadModel at checkpoint zero finds its migration-owned
     *                              table non-empty, stale rows a forgotten checkpoint left behind; the
     *                              operator clears them or resets, never a silent refold over them
     * @throws InvalidRunOptions when `$options->to` is behind the current checkpoint
     * @throws RetryableException when a batch keeps deadlocking past the per-batch retry budget
     * @throws JsonException when the projection topology JSON cannot be serialized or deserialized during setup
     * @throws Exception on a non-retryable DBAL failure of the setup / read / apply / checkpoint
     * @throws Throwable on an unrecoverable error in the loop, a poison event or projection bug; the
     *                   projection is marked Failed and the error re-thrown
     *
     * @infection-ignore-all the wired spine, lease, isolation, retry and mode control: its mutants die in the integration suite against a real Postgres, and unit coverage here would widen the field into that territory
     */
    public function run(string $name, RunOptions $options, ?callable $shouldStop = null): RunOutcome
    {
        $prepared = $this->preflight->prepare($name, $options);
        if ($prepared instanceof RunRefusal) {
            return RunOutcome::stoodDown(StandDown::NotRunnable, $prepared->status);
        }

        $projection = $prepared->projection;
        $profile = $prepared->profile;
        $liveRevision = $prepared->liveRevision;
        $lane = $prepared->lane;
        $store = $lane->store;
        $connection = $lane->connection;

        if (! $store->claimLease($name, $options->owner, $options->leaseTtl)) {
            // The claim gates on BOTH a free lease and a runnable status, so its false carries two
            // causes whose gestures are opposite: wait for the other worker, or resume the hold an
            // operator put there. Reporting one for the other tells a stood-down operator that nothing
            // needs doing over a projection that is waiting for their verb. The row is re-read once,
            // on a path that is already standing down, so the discriminant costs nothing that runs.
            $status = $store->findRow($name)?->status;

            return $status !== null && ! $status->isRunnable()
                ? RunOutcome::stoodDown(StandDown::NotRunnable, $status)
                : RunOutcome::stoodDown(StandDown::LeaseHeld);
        }

        // From here the lease is held: every exit, including a failing initialize(), must release it
        // in the `finally` block, or the projection stays unclaimable for a full TTL with no failure recorded.
        $state = new RunState($profile);
        $pipeline = $lane->runPipeline()->create();
        $startedAt = microtime(true);
        $finalStatus = ProjectionStatus::Idle;
        $failure = null;
        $totalEvents = 0;
        $totalBatches = 0;
        $started = false;
        $previousIsolation = null;

        try {
            // Owner- and runnable-gated flip to Running: an operator's mark:pause/mark:stop landing after
            // claimLease would be overwritten by an unconditional status write; 0 rows means the mark
            // won the window; stand down and let the `finally` block release the fresh lease, whose CASE keeps
            // a Paused; a stop resolves to Idle, same as the loop's cycle-boundary handling.
            if (! $store->markRunning($name, $options->owner)) {
                return RunOutcome::stoodDown(StandDown::MarkedMidClaim);
            }

            $started = true;

            $projection->initialize($connection); // on ITS lane: a read model creates its table where its checkpoint lives; LinkProjection is a no-op

            // ReadBatch reads safeHeadPosition() inside the batch transaction; under READ COMMITTED its
            // per-statement snapshot keeps that watermark fresh, but a REPEATABLE READ tx would pin xmin at
            // AcquireCheckpoint's FOR UPDATE, giving a stale watermark and a silent catch-up stall. Pin READ COMMITTED so
            // a global / pooler / driver default can't break the watermark.
            // The prior level is captured for the `finally`: the runner borrows the connection, it must not
            // leave downgraded isolation behind for whatever shares it after the run. DBAL tracks the level
            // locally, so the capture is exact for a DBAL-set level; one set behind its back, via raw SQL or a pooler,
            // reads as the platform default, and the pin stays, which is all DBAL can know.
            $previousIsolation = $connection->getTransactionIsolation();
            $connection->setTransactionIsolation(TransactionIsolationLevel::READ_COMMITTED);

            do {
                $batchStart = microtime(true);
                $seen = $this->runBatch($connection, $pipeline, $state);
                $totalEvents += $seen;
                $totalBatches++;
                try {
                    $this->obs->recordBatch(new BatchContext(
                        projection: $name,
                        eventCount: $seen,
                        durationMs: (microtime(true) - $batchStart) * 1000,
                        lag: max(0, $state->scanHead - $state->lastPosition),
                        watermarkMs: $state->watermarkMs,
                        applied: $state->applied,
                    ));
                } catch (Throwable) {
                    // Fail-open: telemetry errors must never abort or fail a durably committed projection batch
                }
                $store->renewLease($name, $options->owner, $options->leaseTtl);

                // the contracted post-commit hook, fire-and-forget by contract: once per PRODUCTIVE
                // batch; `applied` is a per-batch counter that runBatch resets, so the test is against
                // zero, not the previous batch. Comparing to the prior batch's count would silently skip a
                // second equally sized batch, since 100 > 100 is false, and every smaller productive tail; a
                // no-op scan with applied 0 stays correctly silent, changing no read model.
                if ($state->applied > 0) {
                    try {
                        $this->commits->committed($name);
                    } catch (Throwable $listenerFailure) {
                        // the runner OWNS the fire-and-forget clause: the batch is durably committed,
                        // so a side-channel throw must never reach the catch below and flip a healthy
                        // projection to Failed. Surfaced through observability, itself fail-open,
                        // the diagnostic channel that cannot re-raise the same outage.
                        $this->obs->recordListenerFailure(new ListenerFailureContext($name, $listenerFailure));
                    }
                }

                // The boundary reads live here, the verdict on them is CycleDecision's: the runner
                // re-reads its own row each cycle, a tiny per-cycle findRow, so a cross-process
                // `mark stop|pause`, possibly from another host, lands within one batch.
                $status = $store->findRow($name)?->status;

                // Derived-rebuild probe, PER CYCLE, riding the same cadence, one more cold PK lookup on
                // `event_link_streams`: the run-start gate only protects runs that START after the
                // rebuild, so for a daemon a producer rebuilt mid-run would stay invisible until the next
                // process restart, an unbounded window of silent incompleteness, while the contract is to
                // refuse rather than bury a link. The refusal is BUILT here, not thrown: an operator's
                // mark landing the same cycle keeps precedence over it in the decision.
                $outOfDate = null;
                if ($profile->sourceStream !== null) {
                    $cycleRevision = $this->derivedRevision->revisionFor($profile->sourceStream);
                    if ($cycleRevision !== $liveRevision) {
                        $outOfDate = ProjectionOutOfDate::sourceRebuilt($name, $profile->sourceStream, $liveRevision, $cycleRevision);
                    }
                }

                $stopRequested = ($shouldStop !== null && $shouldStop()) || $this->maxRuntimeReached($options, $startedAt);
                $decision = CycleDecision::next($options, $status, $outOfDate !== null, $stopRequested, $seen, $state->lastPosition);

                if ($decision === CycleDecision::StopKeepingPause) {
                    $finalStatus = ProjectionStatus::Paused; // keep the pause; don't revert to Idle
                    break;
                }

                if ($decision === CycleDecision::RefuseRebuilt && $outOfDate !== null) {
                    // the null test narrows for phpstan, RefuseRebuilt only ever answering a built
                    // refusal; the throw lands in the catch below, Failed marked, the lease released by
                    // the `finally`, the same refusal the next START would have produced, bounded to one
                    // batch instead of one process lifetime
                    throw $outOfDate;
                }

                if ($decision === CycleDecision::Stop) {
                    break;
                }

                if ($decision === CycleDecision::AwaitEvents) {
                    $this->idleSleep($state); // daemon: wait for new events
                } elseif ($decision === CycleDecision::Productive) {
                    $state->idleMs = $options->backoffMin; // productive cycle, so reset backoff
                }
                // Advance has no side effect: loop again at once, toward the target
            } while (true);
        } catch (LeaseLost) {
            // Another worker claimed the lease while we stalled past the TTL, a clean hand-off, not a
            // failure. Stop looping: the finalStatus stays Idle and failure null, so the owner-gated
            // releaseLease below is a no-op and the new owner's row is left untouched.
        } catch (RetryableException $e) {
            // A transient deadlock that exhausted the per-batch retry budget, by nature retryable, so
            // leave the projection Idle, the default $finalStatus: a later run / daemon cycle retry.
            throw $e;
        } catch (Throwable $e) {
            // Anything else escaping the loop is unrecoverable, e.g. a poison event, a projection bug, a schema /
            // constraint failure: mark Failed so the start guard blocks re-runs until an operator resets,
            // and record the failure context of class, message, and when on the row for triage.
            $finalStatus = ProjectionStatus::Failed;
            $failure = $e;

            throw $e;
        } finally {
            $store->releaseLease($name, $options->owner, $finalStatus, $failure);

            // recordRun keeps its "actually started" contract: a stand-down, where markRunning lost the
            // window to an operator's mark, released the lease but never ran, so no run to record.
            if ($started) {
                $this->obs->recordRun(new RunContext(
                    projection: $name,
                    totalEvents: $totalEvents,
                    totalBatches: $totalBatches,
                    durationMs: (microtime(true) - $startedAt) * 1000,
                    finalStatus: $finalStatus->value,
                    error: $failure,
                ));
            }

            // Restore a deviating isolation level, skipped in the common READ COMMITTED case with no
            // statement. Last on purpose: if the connection died, releaseLease already threw and the
            // session, with its pinned level, is gone anyway.
            if ($previousIsolation !== null && $previousIsolation !== TransactionIsolationLevel::READ_COMMITTED) {
                $connection->setTransactionIsolation($previousIsolation);
            }
        }

        return RunOutcome::ran();
    }

    /**
     * @throws RetryableException when the batch keeps deadlocking past the retry budget
     * @throws RandomException when the backoff jitter cannot draw a random value
     * @throws Throwable propagated from the pipeline: lease lost, a store or apply failure, a DBAL error
     *
     * @infection-ignore-all the wired spine, lease, isolation, retry and mode control: its mutants die in the integration suite against a real Postgres, and unit coverage here would widen the field into that territory
     */
    private function runBatch(Connection $connection, Pipeline $pipeline, RunState $state): int
    {
        for ($attempt = 1; ; $attempt++) {
            $state->resetBatch();

            try {
                return $connection->transactional(
                    static fn (Connection $tx): int => $pipeline->run($state),
                );
            } catch (RetryableException $e) {
                if ($attempt >= self::MAX_TX_ATTEMPTS) {
                    throw $e;
                }

                usleep(random_int(10_000, 50_000)); // brief backoff before retrying the deadlocked batch
            }
        }
    }

    /**
     * @infection-ignore-all wall-clock measurement, integration-tested against live daemon loops
     */
    private function maxRuntimeReached(RunOptions $options, float $startedAt): bool
    {
        return $options->maxRuntime !== null && (microtime(true) - $startedAt) >= $options->maxRuntime;
    }

    /**
     * @infection-ignore-all the sleep is integration-tested against live daemon loops
     */
    private function idleSleep(RunState $state): void
    {
        usleep($state->idleMs * 1000);

        $state->idleMs = min(
            $state->profile->options->backoffMax,
            $state->idleMs + $state->profile->options->backoffStep,
        );
    }
}
