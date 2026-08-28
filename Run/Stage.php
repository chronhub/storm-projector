<?php

declare(strict_types=1);

namespace Storm\Projector\Run;

use Closure;
use Throwable;

/**
 * One step of the per-batch `Pipeline` onion. A stage does its work then calls `$next($state)` to
 * continue, or returns early to short-circuit the rest of the batch. The return is the batch's "seen"
 * count of records fetched, used by the runner loop for backoff/stop.
 *
 * @phpstan-type Next Closure(RunState): int
 *
 * @see Pipeline
 */
interface Stage
{
    /**
     * @param  Closure(RunState): int  $next
     *
     * @throws Throwable a stage's own failure, a lost lease, a store error or a projection apply error,
     *                   or anything propagated from `$next`
     */
    public function __invoke(RunState $state, Closure $next): int;
}
