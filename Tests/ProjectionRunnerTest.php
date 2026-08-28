<?php

declare(strict_types=1);

namespace Storm\Projector\Tests;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\TransactionIsolationLevel;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Storm\Chronicler\Evolution\IdentityEventTypeMapper;
use Storm\EventLinks\DerivedStreamRevision;
use Storm\Projector\Registry\ProjectionRegistry;
use Storm\Projector\Run\PipelineFactory;
use Storm\Projector\Run\ProjectionEventSource;
use Storm\Projector\Run\ProjectionLanes;
use Storm\Projector\Run\ProjectionRunner;
use Storm\Projector\Run\RunOptions;
use Storm\Projector\Run\Stage\AcquireCheckpoint;
use Storm\Projector\Run\Stage\ApplyBatch;
use Storm\Projector\Run\Stage\Checkpoint;
use Storm\Projector\Run\Stage\ReadBatch;
use Storm\Projector\Store\DbalProjectionStore;
use Storm\Projector\Store\ProjectionStatus;
use Storm\Projector\Telemetry\BatchContext;
use Storm\Projector\Telemetry\ListenerFailureContext;
use Storm\Projector\Telemetry\ProjectorObservability;
use Storm\Projector\Telemetry\RunContext;
use Storm\Projector\Tests\Fixture\StubFilteredProjection;
use Storm\Stream\StreamName;
use Throwable;

/**
 * Unit coverage of the runner's exit-classification: the loop's three catch arms map a thrown batch to a
 * final status plus a rethrow/no-rethrow decision. The integration ProjectionRunnerTest drives these over
 * a real Postgres with a lease-stealing or poison read model; here the failure is injected at the FIRST
 * stage, where AcquireCheckpoint reads the checkpoint via the store's `fetchOne`, the production trigger of
 * LeaseLost, so each arm is pinned without a database. The Connection is mocked: `transactional()` runs the
 * closure, the pre-loop store calls succeed, and `fetchOne`, which only the in-pipeline checkpoint read
 * calls, is the lever. The observable is the RunContext the `finally` emits with the SAME finalStatus and
 * failure it hands to `releaseLease`, plus the release SQL the store actually issued.
 */
final class ProjectionRunnerTest extends TestCase
{
    private const string NAME = 'account_balance';

    private const string OWNER = 'worker-a';

    #[Test]
    public function a_lost_lease_releases_idle_with_no_failure_and_does_not_rethrow(): void
    {
        // a checkpoint read finding no owned row raises LeaseLost, a clean hand-off: the runner exits without
        // throwing, releasing Idle and null failure so the new owner's row is left untouched
        $obs = new RecordingObservability;
        // false means the FOR-UPDATE checkpoint select matched no row we still own, so LeaseLost::to() is thrown
        $this->runner($obs, fetchOneResult: false)->run(self::NAME, $this->onceOptions());

        $run = $this->recordedRun($obs);
        $this->assertSame(ProjectionStatus::Idle->value, $run->finalStatus); // not Failed
        $this->assertNull($run->error);                                      // a hand-off carries no failure

        $released = $this->released();
        $this->assertSame(ProjectionStatus::Idle, $released->status);        // releaseLease got Idle
        $this->assertFalse($released->hasError);                            // with no failure context written
    }

    #[Test]
    public function a_retryable_batch_past_its_budget_rethrows_and_releases_idle(): void
    {
        // a deadlock that survives the per-batch retry budget stays retryable by nature, so released Idle, the
        // default, and the runner RETHROWS so a later run / daemon cycle retries it, NOT marked Failed
        $obs = new RecordingObservability;
        $deadlock = $this->retryable('deadlock detected');
        $runner = $this->runner($obs, fetchOneThrows: $deadlock);

        try {
            $runner->run(self::NAME, $this->onceOptions());
            $this->fail('a retryable past its budget must rethrow');
        } catch (RetryableException $e) {
            $this->assertSame($deadlock, $e); // rethrown as-is
        }

        $run = $this->recordedRun($obs);
        $this->assertSame(ProjectionStatus::Idle->value, $run->finalStatus); // retryable, so left Idle
        $this->assertNull($run->error);                                      // not a recorded failure
        $this->assertSame(ProjectionStatus::Idle, $this->released()->status); // releaseLease got Idle
    }

    #[Test]
    public function a_poison_batch_rethrows_and_releases_failed_with_the_cause(): void
    {
        // anything else escaping the loop is unrecoverable, such as a poison event or projection bug: marked Failed so the
        // start guard blocks re-runs, the cause recorded for triage, and rethrown
        $obs = new RecordingObservability;
        $poison = new RuntimeException('a poison record');
        $runner = $this->runner($obs, fetchOneThrows: $poison);

        try {
            $runner->run(self::NAME, $this->onceOptions());
            $this->fail('a poison failure must rethrow');
        } catch (RuntimeException $e) {
            $this->assertSame($poison, $e); // rethrown as-is
        }

        $run = $this->recordedRun($obs);
        $this->assertSame(ProjectionStatus::Failed->value, $run->finalStatus); // unrecoverable, so Failed
        $this->assertSame($poison, $run->error);                               // the exact cause recorded

        $released = $this->released();
        $this->assertSame(ProjectionStatus::Failed, $released->status);        // releaseLease got Failed
        $this->assertTrue($released->hasError);                                // writing the failure context
        $this->assertSame('RuntimeException: a poison record', $released->errorMessage); // the persist-safe digest: class-prefixed, cause chain included
    }

    #[Test]
    public function a_failing_initialize_releases_the_lease_and_marks_failed(): void
    {
        // the lease is claimed BEFORE initialize() mounts the output; a failure there, e.g. a missing
        // migrated table, must release it in the finally and record Failed, not leak the lease for a
        // full TTL with the row silently left idle
        $obs = new RecordingObservability;
        $mountFailure = new RuntimeException('table mount failed');
        $runner = $this->runner($obs, initializeThrows: $mountFailure);

        try {
            $runner->run(self::NAME, $this->onceOptions());
            $this->fail('an initialize failure must rethrow');
        } catch (RuntimeException $e) {
            $this->assertSame($mountFailure, $e); // rethrown as-is
        }

        $run = $this->recordedRun($obs);
        $this->assertSame(ProjectionStatus::Failed->value, $run->finalStatus); // a failed run, recorded
        $this->assertSame($mountFailure, $run->error);

        $released = $this->released();
        $this->assertSame(ProjectionStatus::Failed, $released->status);       // lease released, not leaked
        $this->assertSame('RuntimeException: table mount failed', $released->errorMessage); // failure context persisted, in the digest's class-prefixed form
    }

    #[Test]
    public function a_mark_winning_the_claim_window_stands_down_without_running(): void
    {
        // a mark:pause/stop landing between claimLease and the Running flip makes markRunning affect 0
        // rows: the runner must stand down, release the fresh lease, whose CASE keeps a Paused, and
        // record NO run, nothing started, instead of overwriting the operator's mark
        $obs = new RecordingObservability;
        $runner = $this->runner($obs, markRunningWins: false);

        $runner->run(self::NAME, $this->onceOptions()); // no exception: a stand-down is a clean exit

        $this->assertNull($obs->run);                                  // never started, so no RunContext
        $this->assertSame(ProjectionStatus::Idle, $this->released()->status); // but the lease WAS released
    }

    #[Test]
    public function a_failing_record_batch_telemetry_does_not_abort_or_fail_the_projection(): void
    {
        $obs = new RecordingObservability;
        $obs->onRecordBatch = static function (BatchContext $ctx): void {
            throw new RuntimeException('telemetry sink dead');
        };

        $this->runner($obs, fetchOneResult: 0)->run(self::NAME, $this->onceOptions());

        $run = $this->recordedRun($obs);
        $this->assertSame(ProjectionStatus::Idle->value, $run->finalStatus);
        $this->assertNull($run->error);
        $this->assertSame(ProjectionStatus::Idle, $this->released()->status);
        $this->assertFalse($this->released()->hasError);
    }

    /** Captures the status and error releaseLease was actually issued with, decoded from the store's SQL. */
    private ?ReleasedLease $released = null;

    private function recordedRun(RecordingObservability $obs): RunContext
    {
        $run = $obs->run;
        self::assertNotNull($run, 'the runner must record a RunContext (the lease was claimed)');

        return $run;
    }

    private function released(): ReleasedLease
    {
        self::assertNotNull($this->released, 'releaseLease must have been issued in the finally');

        return $this->released;
    }

    private function onceOptions(): RunOptions
    {
        // "once": no daemon / drain / to; the loop runs a single batch, which throws on the first attempt
        return new RunOptions(owner: self::OWNER, batch: 1);
    }

    private function runner(
        ProjectorObservability $obs,
        mixed $fetchOneResult = 0,
        ?Throwable $fetchOneThrows = null,
        ?Throwable $initializeThrows = null,
        bool $markRunningWins = true,
    ): ProjectionRunner {
        $connection = $this->connection($fetchOneResult, $fetchOneThrows, $markRunningWins);
        $store = new DbalProjectionStore($connection);

        $registry = new ProjectionRegistry([new StubFilteredProjection(self::NAME, $initializeThrows)]);

        $factory = new PipelineFactory(
            new AcquireCheckpoint($store),       // first stage; its `store->acquireCheckpoint` is the lever
            new ReadBatch($this->createStub(ProjectionEventSource::class)),
            new ApplyBatch($connection),
            new Checkpoint($store),
        );

        return new ProjectionRunner(
            $registry,
            ProjectionLanes::single($store, $connection, $factory),
            $obs,
            // never consulted here: the derived-rebuild gate only reads for a projection that declares a
            // source stream, and these are read models. A stub keeps the stub connection out of it.
            new class() implements DerivedStreamRevision
            {
                public function revisionFor(StreamName $target): int
                {
                    throw new LogicException('a filtered projection must not read a derived revision');
                }
            }, eventTypeMapper: new IdentityEventTypeMapper,
        );
    }

    private function connection(mixed $fetchOneResult, ?Throwable $fetchOneThrows, bool $markRunningWins = true): Connection
    {
        // a stub, not a mock: behavior is configured, but the call counts are not the contract under test;
        // the observable is the released status and the rethrow, not "executeStatement was called N times"
        $connection = $this->createStub(Connection::class);

        // the pre-loop store writes, ensure / resumeIfElapsed / markRunning / renewLease, and the lease claim:
        // claimLease needs a positive affected count, the rest ignore it. releaseLease's SQL is captured.
        // markRunning is the only statement setting `status = :status` gated on `lease_owner = :owner`;
        // returning 0 for it simulates an operator's mark winning the claim window.
        $connection->method('executeStatement')->willReturnCallback(function (string $sql, array $params = []) use ($markRunningWins): int {
            if (str_contains($sql, 'lease_owner = NULL')) { // the release SQL; capture which branch ran
                $this->released = ReleasedLease::fromReleaseSql($sql, $params);
            }

            if (! $markRunningWins && str_contains($sql, 'SET status = :status') && str_contains($sql, 'lease_owner = :owner')) {
                return 0; // markRunning lost the window
            }

            return 1;
        });

        // findRow returns false, so a null row: the generation/topology gates and the `--to` gate are all
        // `$row !== null`, so they are skipped and the run proceeds straight to claimLease, then the loop.
        $connection->method('fetchAssociative')->willReturn(false);

        // the isolation pin captures the prior level first, an enum, which a stub cannot auto-generate.
        // READ COMMITTED is the common case, where the finally's restore is skipped, with no statement.
        $connection->method('getTransactionIsolation')->willReturn(TransactionIsolationLevel::READ_COMMITTED);

        // transactional runs the closure, the pipeline, against the same connection, like the real driver.
        $connection->method('transactional')->willReturnCallback(static fn (callable $cb): mixed => $cb($connection));

        // fetchOne is ONLY reached from acquireCheckpoint, inside the pipeline, the single injection point.
        $fetchOne = $connection->method('fetchOne');
        if ($fetchOneThrows !== null) {
            $fetchOne->willThrowException($fetchOneThrows);
        } else {
            $fetchOne->willReturn($fetchOneResult);
        }

        return $connection;
    }

    private function retryable(string $message): RetryableException
    {
        return new class($message) extends RuntimeException implements RetryableException {};
    }
}

/**
 * The status and error a releaseLease call carried, reconstructed from the SQL/params the store issued,
 * so a test asserts what releaseLease was actually called with, not a stand-in.
 *
 * @internal
 */
final readonly class ReleasedLease
{
    private function __construct(
        public ProjectionStatus $status,
        public bool $hasError,
        public ?string $errorMessage,
    ) {}

    /**
     * @param  array<string, mixed>  $params
     */
    public static function fromReleaseSql(string $sql, array $params): self
    {
        // the Failed-with-error branch is the only one that writes error_class, distinguishing it from the
        // Idle/Paused branch. The status enum value rides the bound :status param either way.
        $hasError = str_contains($sql, 'error_class');

        return new self(
            ProjectionStatus::from((string) $params['status']),
            $hasError,
            $hasError ? (string) ($params['errorMessage'] ?? '') : null,
        );
    }
}

/**
 * Captures the single RunContext the runner emits in its `finally`, the observable carrying the SAME
 * finalStatus and failure the runner hands to releaseLease.
 *
 * @internal
 */
final class RecordingObservability implements ProjectorObservability
{
    public ?RunContext $run = null;

    public ?ListenerFailureContext $listenerFailure = null;

    /** @var null|Closure(BatchContext): void */
    public ?Closure $onRecordBatch = null;

    public function recordBatch(BatchContext $ctx): void
    {
        if ($this->onRecordBatch !== null) {
            ($this->onRecordBatch)($ctx);
        }
    }

    public function recordRun(RunContext $ctx): void
    {
        $this->run = $ctx;
    }

    public function recordListenerFailure(ListenerFailureContext $ctx): void
    {
        $this->listenerFailure = $ctx;
    }
}
