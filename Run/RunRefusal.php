<?php

declare(strict_types=1);

namespace Storm\Projector\Run;

use Storm\Projector\Store\ProjectionStatus;

/**
 * The preflight's other verdict: a run the status gate turned away, carrying what it found.
 *
 * The sibling of {@see PreparedRun}, and it exists to carry one fact. A bare null made a refusal
 * indistinguishable from a finished catch-up at the only place that reports to an operator, so the
 * console said "finished" over a projection whose checkpoint had not moved. The status is what names
 * the verb that unblocks, which is the difference between a refusal somebody can act on and a silence
 * they have to investigate.
 */
final readonly class RunRefusal
{
    public function __construct(public ProjectionStatus $status) {}
}
