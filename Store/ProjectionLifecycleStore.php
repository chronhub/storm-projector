<?php

declare(strict_types=1);

namespace Storm\Projector\Store;

use Doctrine\DBAL\Exception;
use JsonException;
use Storm\Projector\Exception\InvalidStatusTransition;
use Storm\Projector\Exception\ProjectionBusy;

/**
 * The operator's store facet: explicit compare-and-set transitions, repair, deletion, and the
 * in-transaction not-running guard. Status transitions carry their expected states INTO the
 * statement; `forceStatus` is the sanctioned unconditional escape for setup and manual repair
 * only. Routed by home when reached from a console or HTTP surface: the row may live on either
 * lane, and a kind/home mismatch is refused rather than guessed.
 *
 * Every method runs against the database, so each throws DBAL's `Exception` on a database
 * failure.
 */
interface ProjectionLifecycleStore
{
    /**
     * An operational month, the ceiling on a timed pause's horizon.
     *
     * It lives on the port because both doors write through it, and an unbounded value only surfaces
     * later as a PostgreSQL interval error instead of a clear refusal at the edge. An operator holding
     * a projection longer than this wants `stop`, which needs no horizon at all.
     */
    public const int MAX_PAUSE_SECONDS = 2_592_000;

    /**
     * Force the status with NO expected-state predicate: whatever the row says now, it says `$status`
     * next.
     *
     * For setup and manual repair only. An operator transition must NOT use this: it decides on a status
     * it read a moment ago, and a worker failing in between makes the decision stale: writing `idle` over
     * the `Failed` that appeared meanwhile erases the very state that protects a poison batch from being
     * re-run. Use `pause()` / `resume()` / `requestStop()` / `retry()`, which carry the expected states
     * into the statement.
     *
     * @throws Exception
     */
    public function forceStatus(string $name, ProjectionStatus $status): void;

    /**
     * Ask a projection to stop, resolving the LIVE-LEASE question and the transition in one statement.
     *
     * With a live worker the row goes to `stopping`, which that worker observes and winds down on; with
     * none there is nobody to observe it, so it realigns straight to `idle` rather than parking on a
     * `stopping` only a reset could clear. Evaluating the lease separately from the write is what let a
     * crashed-then-Failed worker be overwritten with `idle`.
     *
     * @return ProjectionStatus|null the status it landed in, or null when the row was no longer in `$from`
     *
     * @throws InvalidStatusTransition when `$from` is empty, a compare-and-set with no comparison
     * @throws Exception
     */
    public function requestStop(string $name, ProjectionStatus ...$from): ?ProjectionStatus;

    /**
     * Pause the projection, only while it is still in one of `$from`. With `$forSeconds` it is a timed
     * pause, bounded by `MAX_PAUSE_SECONDS`: a worker will not claim it until that elapses, then it
     * auto-resumes via resumeIfElapsed. Without, it is an indefinite pause held until an explicit
     * resume.
     *
     * The precondition travels IN the statement: pass the states the caller validated against, derived
     * from the predicate it validated with. `$from` empty is refused as a coding error rather than
     * silently meaning "any"; setup and repair have `forceStatus()` for that intent.
     *
     * @return bool false when the row was no longer in `$from`
     *
     * @throws InvalidStatusTransition when `$from` is empty, a compare-and-set with no comparison
     * @throws Exception
     */
    public function pause(string $name, ?int $forSeconds = null, ProjectionStatus ...$from): bool;

    /**
     * Resume from a pause, timed or indefinite, only while the row is still in one of `$from`: back to
     * `idle`, the pause horizon cleared. Same compare-and-set contract as `pause()`: the caller passes
     * the states its own predicate accepted, never a list this method hardcodes on its behalf.
     *
     * @return bool false when the row was no longer in `$from`
     *
     * @throws InvalidStatusTransition when `$from` is empty, a compare-and-set with no comparison
     * @throws Exception
     */
    public function resume(string $name, ProjectionStatus ...$from): bool;

    /**
     * Reset to a clean "never run" state: checkpoint 0, status `idle`, lease cleared, failure context and
     * pause horizon cleared, and `generation` realigned to the rebuilt definition's `$generation`, since
     * the read model is about to be replayed under it. Call inside the same transaction that clears the
     * output.
     *
     * @throws Exception
     */
    public function reset(string $name, int $generation = 0): bool;

    /**
     * Recover a failed projection without replaying, only while the row is still in one of `$from`: clear
     * the failure context, set status to `idle`, but keep the checkpoint, since the failed batch rolled
     * back so the position is consistent, and keep the `generation`, since it is a continuation, not a new
     * replay. The next run catches up from where it stopped. Contrast reset, which zeroes the checkpoint
     * and bumps `generation` for a full rebuild.
     *
     * Compare-and-set like the other transition verbs: the caller passes the states its retry predicate
     * accepted, so a concurrent transition landing between its read and this write loses nothing: the
     * write simply does not match, and the caller reports the fresh truth instead of erasing it.
     *
     * @return bool false when the row was no longer in `$from`, so nothing was cleared
     *
     * @throws InvalidStatusTransition when `$from` is empty, a compare-and-set with no comparison
     * @throws Exception
     */
    public function retry(string $name, ProjectionStatus ...$from): bool;

    /**
     * Delete the projection's state row.
     *
     * @throws Exception
     */
    public function delete(string $name): bool;

    /**
     * Lock the projection row `FOR UPDATE` and assert no worker holds a live lease, the in-transaction
     * guard for a lifecycle op: reset, delete, retire, retry, or forget. Call it first inside the op's
     * transaction: holding the row lock serializes a concurrent `claimLease`, which then blocks until the
     * op commits and re-evaluates its guard, closing the TOCTOU a separate pre-transaction `hasLiveLease`
     * check leaves open. It holds whether or not the lease is free. An ABSENT row has no row lock to take,
     * PostgreSQL holding none for a key that does not exist; the guard then falls back to the advisory key
     * `ensure()` mints under and re-reads, so a lifecycle op on a name that never ran still serializes
     * against the first run minting it.
     *
     * @throws ProjectionBusy when a worker holds a live lease
     * @throws Exception
     */
    public function lockAndAssertNotRunning(string $name): void;

    /**
     * Read one projection's state row, or null when the name has no row; a transition caller reads
     * the states it will carry into its compare-and-set.
     *
     * @throws JsonException
     * @throws Exception
     */
    public function findRow(string $name): ?ProjectionRow;
}
