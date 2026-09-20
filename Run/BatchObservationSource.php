<?php

declare(strict_types=1);

namespace Storm\Projector\Run;

use Closure;

/** Optional observations owned by one batch attempt, discarded when that attempt fails. */
interface BatchObservationSource
{
    /**
     * Transfers the current notification to the runner, which invokes it only after batch success.
     *
     * @return Closure(): void|null
     */
    public function takeBatchObservation(): ?Closure;
}
