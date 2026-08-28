<?php

declare(strict_types=1);

namespace Storm\Projector\Telemetry;

use Throwable;

/**
 * Carries the totals of a ProjectionRunner::run call, only fired when the runner actually started, with
 * the lease claimed AND flipped to running. The early returns before any batch emit nothing: the lease
 * held by another worker, the projection unrunnable at start, the target behind the checkpoint, and a
 * mark:pause or mark:stop winning the claim window.
 *
 * finalStatus is the projection's persisted status at exit: Idle after a normal completion, Paused if the
 * runner stopped because the status was flipped mid-cycle, and so on. error carries the unrecoverable
 * Throwable when finalStatus is failed, null otherwise, so the observability fanout can log it at error
 * level and render its backtrace.
 */
final readonly class RunContext
{
    public function __construct(
        /** Projection name, the registry key. */
        public string $projection,
        /** Sum of eventCount across batches. */
        public int $totalEvents,
        /** Number of batches the loop completed, productive or idle. */
        public int $totalBatches,
        /** Wall-clock duration from lease-claimed to exit, in milliseconds. */
        public float $durationMs,
        /** ProjectionStatus value at exit: idle, paused, or failed. */
        public string $finalStatus,
        /** The unrecoverable error when finalStatus is failed; null otherwise. */
        public ?Throwable $error = null,
    ) {}
}
