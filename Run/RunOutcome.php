<?php

declare(strict_types=1);

namespace Storm\Projector\Run;

use Storm\Projector\Store\ProjectionStatus;

/**
 * What a run attempt did, so a caller can tell a finished catch-up from a run that never began.
 *
 * A `void` return made the three stand-downs indistinguishable from success at the only place that
 * reports to an operator, and the console said "finished" over a projection whose checkpoint had not
 * moved. Read as a deployment gate that is a lie, and under a supervisor as a hot loop: exit zero,
 * relaunch, refuse, exit zero, each turn paying a full framework boot and triggering nothing.
 *
 * Standing down is not failing. The status the run found is carried so the caller names it, and with
 * it the verb that unblocks, which is the difference between a refusal an operator can act on and a
 * silence they have to investigate.
 */
final readonly class RunOutcome
{
    private function __construct(
        public ?StandDown $standDown,
        public ?ProjectionStatus $status,
    ) {}

    public static function ran(): self
    {
        return new self(null, null);
    }

    /**
     * @param  ProjectionStatus|null  $status  what the run found, when it read a row at all
     */
    public static function stoodDown(StandDown $reason, ?ProjectionStatus $status = null): self
    {
        return new self($reason, $status);
    }

    public function started(): bool
    {
        return $this->standDown === null;
    }
}
