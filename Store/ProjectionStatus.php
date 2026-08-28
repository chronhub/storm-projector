<?php

declare(strict_types=1);

namespace Storm\Projector\Store;

/**
 * A projection's lifecycle status, a 5-state machine.
 *
 * - `Idle`: at rest, no lease held; free to be claimed and run.
 *
 * - `Running`: a worker holds the lease and is processing batches.
 *
 * - `Paused`: deliberately held by an operator; not claimed until resumed. A timed pause sets
 *   `pause_until` and auto-resumes once it elapses, the runner flipping it back to `idle` at the next run.
 *
 * - `Stopping`: a cross-process stop was requested; a running daemon exits the next cycle, back to `Idle`.
 *
 * - `Failed`: an unrecoverable error stopped the run; non-runnable until `reset`.
 *
 * Reset/delete are synchronous on a stopped projection, with no `pending_*` transitional states.
 *
 * The transition rules live here, the single source of truth: the runner's start guard and the
 * `mark`/`retry` console commands all delegate to these predicates, so the state machine cannot drift
 * across call sites.
 */
enum ProjectionStatus: string
{
    case Idle = 'idle';
    case Running = 'running';
    case Paused = 'paused';
    case Stopping = 'stopping';
    case Failed = 'failed';

    /**
     * May a worker attempt to claim and run it? Idle, at rest, and Running, where the lease arbitrates a
     * second worker, qualify; Paused, Stopping and Failed are deliberately held or broken.
     */
    public function isRunnable(): bool
    {
        return $this === self::Idle || $this === self::Running;
    }

    /**
     * The status values for which isRunnable() is true, the single source the `claimLease` SQL gate reads,
     * so a claim can never run a projection the start guard would reject. Derived from isRunnable so the two
     * cannot drift.
     *
     * @return list<string>
     */
    public static function runnableValues(): array
    {
        return array_filter(self::cases(), static fn (self $status): bool => $status->isRunnable())
                |> (static fn ($x) => array_map(static fn (self $status): string => $status->value, $x))
                |> array_values(...);
    }

    /**
     * The wire values of the given cases, for a compare-and-set `status IN (…)`.
     *
     * @return list<string>
     */
    public static function valuesOf(self ...$statuses): array
    {
        return array_values(array_map(static fn (self $status): string => $status->value, $statuses));
    }

    /**
     * `mark pause`: only a running or resting projection can be paused.
     */
    public function canPause(): bool
    {
        return $this === self::Idle || $this === self::Running;
    }

    /**
     * `mark resume`: only a paused projection un-pauses. A `Failed` one recovers via `retry`/`reset`.
     */
    public function canResume(): bool
    {
        return $this === self::Paused;
    }

    /**
     * `mark stop`: a running projection has something to stop; re-stopping a `Stopping` one is idempotent,
     * and the door out of a row stuck there by a crashed worker. Liveness is NOT part of the state machine:
     * `status` can be stale after a crash, so ProjectionStore::requestStop resolves what "stop" means
     * against the lease INSIDE its own statement's CASE, atomic with the write; a live worker gets the
     * Stopping request, a dead one has nothing to stop, and the status realigns to `idle`.
     */
    public function canStop(): bool
    {
        return $this === self::Running || $this === self::Stopping;
    }

    /**
     * `retry`: clear the failure and resume from the checkpoint; only valid from `Failed`.
     */
    public function canRetry(): bool
    {
        return $this === self::Failed;
    }
}
