<?php

declare(strict_types=1);

namespace Storm\Projector\Run;

use Storm\Projector\Definition\PersistentProjection;

/**
 * The preflight's verdict: a run every gate has admitted, carrying what the lease-and-loop half
 * needs and nothing it must re-derive. `liveRevision` is the derived stream's revision read ONCE
 * at preflight; the loop's per-cycle gate compares against this same value, so a producer rebuild
 * between two cycles is caught against the baseline the run started under.
 */
final readonly class PreparedRun
{
    public function __construct(
        public PersistentProjection $projection,
        public ProjectionLane $lane,
        public RunProfile $profile,
        public int $liveRevision,
    ) {}
}
