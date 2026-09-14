<?php

declare(strict_types=1);

namespace Storm\Projector\Exception;

use RuntimeException;

/**
 * Thrown inside a batch when the running worker no longer owns the lease, another worker having claimed
 * it while this one was stalled past its TTL. A normal hand-off, not a failure: the runner catches it,
 * ends the run without marking the projection Failed, and reports a dispossession, the new owner already
 * advancing the projection. The console then exits non-zero on it, since the work the process was
 * launched for is unfinished and only a non-zero exit brings the worker back under a supervisor.
 *
 * Correctness never depended on the lease: the per-batch `acquireCheckpoint` `FOR UPDATE` and monotonic
 * `sequence_no` prevent double-apply; this check just stops the stale worker from spinning empty cycles
 * as a zombie. The test it fails is ownership, never freshness: a lease merely aged past its TTL, still
 * held by this worker, is renewed and the run goes on.
 */
final class LeaseLost extends RuntimeException
{
    public static function to(string $name, string $owner): self
    {
        return new self(sprintf(
            'Worker "%s" lost the lease on projection "%s" (claimed by another worker) — exiting.',
            $owner,
            $name,
        ));
    }
}
