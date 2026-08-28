<?php

declare(strict_types=1);

namespace Storm\Projector\Run;

use Doctrine\DBAL\Exception;
use JsonException;
use Storm\Contracts\Chronicler\EventTypeMapper;
use Storm\EventLinks\DerivedStreamRevision;
use Storm\Projector\Definition\FanOutLinkProjection;
use Storm\Projector\Definition\LinkProjection;
use Storm\Projector\Definition\MigratedReadModel;
use Storm\Projector\Definition\PersistentProjection;
use Storm\Projector\Exception\InvalidRunOptions;
use Storm\Projector\Exception\ProjectionHomeMismatch;
use Storm\Projector\Exception\ProjectionOutOfDate;
use Storm\Projector\Exception\StaleMigratedContent;
use Storm\Projector\Exception\UnknownProjection;
use Storm\Projector\Exception\UnsupportedProjection;
use Storm\Projector\Registry\ProjectionRegistry;
use Storm\Projector\Store\ProjectionMode;

/**
 * The run's gate sequence, every check a start must pass BEFORE the lease is claimed: resolution
 * and kind, home, stale migrated content, generation, topology, derived rebuild, target sanity,
 * then the row ensured and its baselines stamped. One entry point, so no gate is skippable by
 * wiring; the order is the order the refusals surface in, and a refused run leaves the stored row
 * untouched, since it is the evidence of what the checkpoint was built under.
 *
 * @see ProjectionRunner the caller, which owns the lease and the loop
 */
final readonly class ProjectionRunPreflight
{
    public function __construct(
        private ProjectionRegistry $registry,
        private ProjectionLanes $lanes,
        private DerivedStreamRevision $derivedRevision,
        private EventTypeMapper $eventTypeMapper,
    ) {}

    /**
     * Prepares one run, or answers a `RunRefusal` carrying the status that refused it: Paused,
     * Stopping or Failed. The refusal is typed rather than empty because the three call for opposite
     * gestures, and a caller handed nothing can only report that nothing happened.
     *
     * @throws UnknownProjection when no projection is registered under `$name`
     * @throws UnsupportedProjection when `$name` is a QueryProjection rather than a persistent one, or
     *                               declares neither or both selections
     * @throws ProjectionHomeMismatch when `$name`'s state sits on a database home its current kind does
     *                                not own, the trace of a kind change shipped without a state-home
     *                                migration
     * @throws ProjectionOutOfDate when the declared generation differs from the built-under one without
     *                             `allowStale`, or the declared selection drifted from the one the kept
     *                             checkpoint was built under, or the consumed derived stream was rebuilt
     *                             under the kept checkpoint; none of the three bypassable by `allowStale`
     *                             except the generation gate
     * @throws StaleMigratedContent when a MigratedReadModel at checkpoint zero finds its migration-owned
     *                              table non-empty, stale rows a forgotten checkpoint left behind; the
     *                              operator clears them or resets, never a silent refold over them
     * @throws InvalidRunOptions when `$options->to` is behind the current checkpoint
     * @throws JsonException when the projection topology JSON cannot be serialized or deserialized
     * @throws Exception on a DBAL failure of the probes or the row write
     *
     * @infection-ignore-all the gate sequence over the wired store: its refusals are enforced by the integration suites against a real Postgres, and unit coverage here would widen the field into that territory
     */
    public function prepare(string $name, RunOptions $options): PreparedRun|RunRefusal
    {
        $projection = $this->registry->get($name);

        if (! $projection instanceof PersistentProjection) {
            throw UnsupportedProjection::notPersistent($name);
        }

        // the projection's HOME: its checkpoint, its writes and the batch tx live on ITS lane
        $lane = $this->lanes->laneFor($projection);
        $this->lanes->assertHomedOn($name, $lane); // a kind change moved the home; the state did not follow
        $store = $lane->store;

        $profile = RunProfile::for($projection, $options, $this->eventTypeMapper);

        $mode = $profile->sourceStream !== null ? ProjectionMode::Derived : ProjectionMode::Filtered;

        // A stream producer's output is recorded on the row so forget() can clean its links once the
        // class is removed, with no instance to delegate to: the fixed target stream of a LinkProjection,
        // or the prefix a fan-out's computed targets live under. Mutually exclusive by type.
        $targetStream = $projection instanceof LinkProjection ? $projection->targetStream() : null;
        $targetPrefix = $projection instanceof FanOutLinkProjection ? $projection->targetPrefix() : null;

        // A derived consumer's checkpoint is bound to its source stream's MEMBERSHIP, not just its
        // length, so the live revision is read once here and used by both the gate and the stamp below.
        $liveRevision = $profile->sourceStream !== null ? $this->derivedRevision->revisionFor($profile->sourceStream) : 0;

        $store->resumeIfElapsed($name); // a timed pause past its pause_until auto-resumes, so guard sees Idle

        // The gates read the row BEFORE ensure() refreshes it: a refused run must leave the stored
        // topology untouched; it is the evidence of what the checkpoint was built under.
        $row = $store->findRow($name);
        if ($row !== null && ! $row->status->isRunnable()) {
            // Paused / Stopping / Failed; the status travels out so the caller can name it and the
            // verb that unblocks, rather than reporting a run that never began as a finished one
            return new RunRefusal($row->status);
        }

        // stale-content gate, MigratedReadModel only. At checkpoint zero, no row or a reset one,
        // storm has folded nothing, so a non-empty migration-owned table can only hold STALE content:
        // the trace of `forget`, which drops the tracking row but leaves the table to the migration,
        // followed by the class coming back and restarting at position 0. Refolding the history over
        // it double-counts in silence on an additive fold; the defect delete() closes from the other
        // side by emptying the content storm owns. One existence probe on the cold first-run path,
        // never per batch; placed before any write, so the refused run leaves no row and no stamp.
        // Not bypassable by --allow-stale: that flag serves stale data knowingly, this would corrupt
        // forward, the same posture as the topology gate. The exits clear the content: reset, or a
        // truncate by hand.
        if ($projection instanceof MigratedReadModel
            && ($row === null || $row->lastPosition === 0)
            && $projection->hasContent($lane->connection)) {
            throw StaleMigratedContent::atCheckpointZero($name);
        }

        if ($row !== null) {
            // generation gate. A stored generation of 0 is unstamped, a fresh row or one just reset, so
            // adopt the current code's generation, stamped below, after the topology gate, so a refused
            // run writes nothing. A non-zero mismatch means the read model was built by an older
            // generation, so refuse unless --allow-stale; rebuild via reset to realign.
            if ($row->generation !== 0 && ! $options->allowStale && $row->generation !== $projection->generation()) {
                throw ProjectionOutOfDate::generationMismatch($name, $projection->generation(), $row->generation);
            }

            // topology gate. The selection is frozen by the checkpoint, whose position only has meaning
            // against the perimeter it was read under: a drifted selection over a non-zero checkpoint
            // would leave silent holes below it; refuse, and point at the sanctioned exit of bump and reset.
            // Reset zeroes the checkpoint, so the rebuilt run passes here and ensure() re-records freely.
            // Deliberately NOT bypassed by --allow-stale: that flag serves stale data knowingly, this
            // would corrupt forward. A dev who bumps generation() never reaches this gate un-reset.
            if ($row->lastPosition > 0) {
                $drift = TopologyDrift::between($row, $mode->value, $profile, $targetStream, $targetPrefix);
                if ($drift !== []) {
                    throw ProjectionOutOfDate::topologyDrift($name, $drift);
                }
            }

            // derived-rebuild gate. The topology gate above compares DECLARATIONS; this one compares the
            // stream itself. A consumer's checkpoint asserts that everything linked below it was folded,
            // and a producer rebuild can insert a source position under that checkpoint without changing
            // anything the consumer declares or any position it can observe; `max(source_sequence)` is
            // unchanged or higher either way, so the consumer reports itself caught up and the link is
            // invisible forever. The revision is the only witness. Same posture as topology drift: not
            // bypassable by --allow-stale, and the exit is reset, which zeroes the checkpoint and
            // re-adopts the current revision below.
            if ($profile->sourceStream !== null && $row->lastPosition > 0 && $row->sourceRevision !== $liveRevision) {
                throw ProjectionOutOfDate::sourceRebuilt($name, $profile->sourceStream, $row->sourceRevision, $liveRevision);
            }

            if ($row->generation === 0) {
                $store->stampGeneration($name, $projection->generation());
            }
        }

        if ($options->to !== null && $row !== null && $options->to < $row->lastPosition) {
            throw InvalidRunOptions::targetBehindCheckpoint($name, $options->to, $row->lastPosition);
        }

        $store->ensure($name, $mode->value, $profile->categories, $profile->eventClasses, $targetStream, $targetPrefix, $projection->generation(), $profile->sourceStream);

        // Bind the revision while there is nothing to invalidate: at checkpoint 0 the consumer has folded
        // nothing, so whatever the stream currently is becomes its baseline.
        //
        // The `lastPosition === 0` guard is REDUNDANT as the code stands: the gate above
        // refuses every mismatch before reaching here, so any run that gets this far
        // already has stored == live and re-stamping would write the same value. It is kept because it
        // states the invariant the gate relies on, that the binding is made once against a checkpoint of 0,
        // and that invariant would otherwise live nowhere, silently depending on this line sitting below
        // the gate rather than above it.
        if ($profile->sourceStream !== null && ($row === null || $row->lastPosition === 0)) {
            $store->stampSourceRevision($name, $liveRevision);
        }

        return new PreparedRun($projection, $lane, $profile, $liveRevision);
    }
}
