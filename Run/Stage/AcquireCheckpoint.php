<?php

declare(strict_types=1);

namespace Storm\Projector\Run\Stage;

use Closure;
use Doctrine\DBAL\Exception;
use Storm\Projector\Exception\LeaseLost;
use Storm\Projector\Run\RunState;
use Storm\Projector\Run\Stage;
use Storm\Projector\Store\ProjectionRunStore;

/**
 * Reads and locks, `FOR UPDATE`, the projection's checkpoint into the state. Runs inside the batch
 * transaction so the later advance is atomic with the events applied. Re-asserts lease ownership in the
 * same lock: a worker that stalled past its TTL and had the lease claimed by another gets `LeaseLost`
 * here, before any read/apply, and the runner exits cleanly.
 *
 * @see LeaseLost
 */
final readonly class AcquireCheckpoint implements Stage
{
    public function __construct(
        private ProjectionRunStore $store,
    ) {}

    /**
     * @throws LeaseLost when the lease was claimed by another worker
     * @throws Exception on a DBAL failure of the checkpoint read
     */
    public function __invoke(RunState $state, Closure $next): int
    {
        $state->lastPosition = $this->store->acquireCheckpoint($state->profile->name, $state->profile->options->owner);

        return $next($state);
    }
}
