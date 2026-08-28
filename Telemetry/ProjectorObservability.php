<?php

declare(strict_types=1);

namespace Storm\Projector\Telemetry;

/**
 * Observability port for the projection runner, the single seam through which `ProjectionRunner`
 * surfaces operational data. Body code calls one method per significant event with a typed Context DTO;
 * the implementation decides what to do, whether a structured log, metrics, an audit DB write, or a
 * no-op.
 *
 * The default implementation is a null one that ships with the Projector package. The Telemetry
 * implementation, when installed, fans this port out to Monolog plus optional metrics.
 *
 * FAIL-OPEN is the implementation's contract, like the event-store observability port, and like the
 * post-commit `ProjectionCommitListener`, whose fire-and-forget clause the runner enforces itself: a
 * listener throw is absorbed and lands here as `recordListenerFailure()`, never in the run's outcome.
 * `recordBatch()` fires AFTER the batch transaction has committed but inside the runner's
 * `catch (Throwable)` region, and `recordRun()` fires in the runner's `finally`: an implementation that
 * throws would flip a DURABLY COMMITTED projection to Failed, blocking its re-runs until an operator
 * reset, or mask the primary failure a run was already carrying. Observability must never change the
 * runner's outcome: catch your own sink failures, count them, and return; a throwing sink is a bug in
 * the implementation, not a condition the runner handles.
 *
 * NON-BLOCKING is the contract's other half: `recordBatch()` fires once per loop cycle, so an
 * implementation's latency is paid on every cycle of every running projection, and a daemon polls
 * forever. An implementation must not perform synchronous network I/O on these hooks; a logging
 * fanout needs buffered or local handlers, never a socket handler awaiting a remote ack.
 */
interface ProjectorObservability
{
    /**
     * A batch was applied successfully, transactional and post-retry if any. Fires per cycle of the
     * runner's loop; can be noisy on a slow projection, so filter at the log channel level. Not called
     * when a batch exhausts its deadlock-retry budget and re-throws.
     */
    public function recordBatch(BatchContext $ctx): void;

    /**
     * A run() call ended, fired in the finally of the runner, on every code path that actually started
     * running, with the lease claimed AND flipped to running. Not called when the runner returns early
     * before any batch: the lease held by another worker, the projection paused, stopping or failed at
     * start, the target behind the checkpoint, or a mark:pause/stop winning the claim window, a
     * stand-down rather than a run. Carries the post-run totals and the projection's final status.
     */
    public function recordRun(RunContext $ctx): void;

    /**
     * A post-commit `ProjectionCommitListener` threw and the runner absorbed it: the batch is durably
     * committed, the projection keeps running. The side-channel failure still needs an operator's eye,
     * since a purge that keeps failing means stale caches until TTL, so it is surfaced here, on the port
     * whose own contract is fail-open, instead of flipping a healthy projection to Failed.
     */
    public function recordListenerFailure(ListenerFailureContext $ctx): void;
}
