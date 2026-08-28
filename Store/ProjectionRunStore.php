<?php

declare(strict_types=1);

namespace Storm\Projector\Store;

use Doctrine\DBAL\Exception;
use JsonException;
use Storm\EventLinks\DerivedStreamRevision;
use Storm\Projector\Exception\LeaseLost;
use Storm\Stream\StreamName;
use Throwable;

/**
 * The run path's store facet: what the preflight, the batch stages and the runner's lease spine
 * call, always on the projection's OWN lane, inside or around the batch transaction. Never routed
 * by home: a run holds its lane-local store, so this facet deliberately has no homed router and no
 * container alias; wiring hands it through the lane.
 *
 * Every method runs against the database, so each throws DBAL's `Exception` on a database
 * failure; the methods that serialize or deserialize the topology JSON additionally throw
 * `JsonException`.
 */
interface ProjectionRunStore
{
    /**
     * Idempotently create the row, status `idle` and checkpoint 0, or refresh its declared topology,
     * keeping the existing status/checkpoint/lease.
     *
     * The overwrite is only safe because the runner gates before calling ensure: a selection that drifted
     * over a kept checkpoint is refused there as `ProjectionOutOfDate`, with the stored topology left
     * intact as the evidence. `$eventClasses` holds the declared event classes, the dev's intent, not the
     * alias-resolved stored types, so an added `#[EventType]` alias never reads as a topology change.
     * `$sourceStream` is the read source of a DerivedStreamProjection.
     *
     * `$targetStream`/`$targetPrefix` record a stream producer's output on the row itself, so forget can
     * clean the links of an orphaned producer whose class was removed with no instance to ask: the fixed
     * target of a LinkProjection, or the declared prefix a fan-out's many targets live under. Mutually
     * exclusive; both null for a read model.
     *
     * The mint runs under an advisory lock on the name, in the implementation's own transaction: a row's
     * very first appearance is the one write a lifecycle op's row lock cannot serialize, so
     * `lockAndAssertNotRunning` falls back to the same key on an absent row.
     *
     * @param  list<string>  $categories
     * @param  list<string>  $eventClasses
     *
     * @throws JsonException
     * @throws Exception
     */
    public function ensure(string $name, string $mode, array $categories, array $eventClasses, ?StreamName $targetStream, ?string $targetPrefix = null, int $generation = 0, ?StreamName $sourceStream = null): void;

    /**
     * Read the checkpoint, the last applied position or 0 when fresh, and lock the row `FOR UPDATE`;
     * call inside the batch transaction so the advance is atomic with the events applied.
     *
     * Re-asserts lease ownership in the same lock: if the row is no longer ours, another worker having
     * claimed it while we stalled past the TTL, no row matches and the implementation throws `LeaseLost`,
     * so the runner exits cleanly instead of spinning as a zombie. The check is on ownership, not
     * freshness: a merely aged lease still owned by us proceeds normally.
     *
     * @throws LeaseLost when the lease has been claimed by another worker
     * @throws Exception
     */
    public function acquireCheckpoint(string $name, string $owner): int;

    /**
     * Advance the checkpoint to the applied position; call inside the batch transaction that applied
     * it. The owner predicate makes the guard LOCAL to the statement that depends on it: today the
     * acquire stage's `FOR UPDATE ... lease_owner = :owner` already serializes the row, but that
     * protection lives in the stage ORDER, one file away, and a reordering would remove it with
     * nothing failing; the predicate refuses loud instead.
     *
     * @throws LeaseLost when the row no longer carries this owner's lease; with the acquire lock
     *                   held in the same transaction this is unreachable, so reaching it means the
     *                   stage order itself was broken
     * @throws Exception
     */
    public function advance(string $name, int $position, string $owner): void;

    /**
     * Soft-claim the lease: succeeds when the lease is free, expired, or already held by `$owner` and the
     * status is runnable. Returns false when another live owner holds it, or the projection is not
     * runnable, being paused, stopping, or failed. Gating on status here, atomic with the lease check,
     * closes the start-window race where a `mark:pause`/`mark:stop` landing after the runner's upstream
     * status read would be overwritten by a status write. A timed pause is auto-resumed by
     * resumeIfElapsed first. Per the "status transitions are explicit" rule, this only reads status, it
     * does not change it; the runnable set is derived from ProjectionStatus::isRunnable, the single source.
     *
     * @throws Exception
     */
    public function claimLease(string $name, string $owner, int $ttlSeconds): bool;

    /**
     * Flip the freshly-claimed projection to `running`, owner-gated AND runnable-gated, atomically. Closes
     * the claim-to-running window: a `mark:pause`/`mark:stop` landing after claimLease succeeded would be
     * silently overwritten by an unconditional status write; here the operator's mark makes the row
     * unmatchable, its status no longer runnable, the update affects 0 rows, and the caller stands down,
     * releasing its fresh lease whose CASE keeps a Paused, instead of running. The runnable set is the same
     * single source claimLease gates on, ProjectionStatus::isRunnable.
     *
     * @return bool false when a pause/stop won the window, or the lease moved on; do not run
     *
     * @throws Exception
     */
    public function markRunning(string $name, string $owner): bool;

    /**
     * Extend the lease, only the current owner. Throws `LeaseLost` when the lease has moved on, the
     * UPDATE matching no owned row, so a stale worker detects the loss promptly and hands off at once
     * rather than spinning empty cycles until the next checkpoint acquisition would notice.
     *
     * @throws LeaseLost when the lease has been claimed by another worker
     * @throws Exception
     */
    public function renewLease(string $name, string $owner, int $ttlSeconds): void;

    /**
     * Release the lease, only the current owner, and set the final status, `idle` by default. A run that
     * stopped on a cross-process `pause` passes ProjectionStatus::Paused so the pause sticks instead of
     * reverting to `idle`.
     *
     * When the run ended on an unrecoverable error, pass ProjectionStatus::Failed and the `$error`: the
     * row records `failed_at` as now, plus the error class and the capped message, for triage. The full
     * backtrace belongs in telemetry/logs, not the row. The failure context is cleared on reset.
     *
     * @throws Exception
     *
     * @see ProjectionStatus::Paused
     * @see ProjectionStatus::Failed
     */
    public function releaseLease(string $name, string $owner, ProjectionStatus $status = ProjectionStatus::Idle, ?Throwable $error = null): void;

    /**
     * Auto-resume a timed pause whose horizon has elapsed: flip `paused` to `idle` and clear the horizon.
     * No-op for an indefinite pause or one not yet elapsed. Called at the start of a run so a snoozed
     * projection comes back on its own; the database clock decides, never a PHP-parsed timestamp.
     *
     * @throws Exception
     */
    public function resumeIfElapsed(string $name): void;

    /**
     * Stamp the build generation the read model is being built under; the run-start gate calls this for an
     * unstamped row, generation 0, whether fresh, pre-feature or just reset, to adopt the current
     * definition's generation, so a later definition change can be detected as a mismatch.
     *
     * @throws Exception
     */
    public function stampGeneration(string $name, int $generation): void;

    /**
     * Stamp the membership revision the source derived stream carried when this checkpoint started; the
     * run-start gate calls this while the checkpoint is still 0, so the value is frozen against a
     * position of 0 and every later run compares against it. Only meaningful for a
     * DerivedStreamProjection; a filtered projection has no source stream to bind.
     *
     * @throws Exception
     *
     * @see DerivedStreamRevision
     */
    public function stampSourceRevision(string $name, int $revision): void;

    /**
     * Read one projection's state row, or null when the name has no row; the gates and the loop's
     * cross-process mark check read through this.
     *
     * @throws JsonException
     * @throws Exception
     */
    public function findRow(string $name): ?ProjectionRow;
}
