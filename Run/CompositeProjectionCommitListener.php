<?php

declare(strict_types=1);

namespace Storm\Projector\Run;

use Storm\Contracts\Projector\ProjectionCommitListener;
use Throwable;

/**
 * Fan-out of the post-commit hook: the port names ONE listener, the use case is plural, an HTTP
 * cache purge beside a metrics tick, so the bundle aliases the port to this composite over every
 * tagged implementation and installing one listener can never evict another.
 *
 * Each delegate is isolated: a throwing one, already a contract violation, must not starve its
 * siblings of a commit signal. The first failure is relayed AFTER every delegate was served, so
 * the runner's absorbing net still surfaces it through observability, never through the run's
 * outcome.
 */
final readonly class CompositeProjectionCommitListener implements ProjectionCommitListener
{
    /**
     * @param  iterable<ProjectionCommitListener>  $listeners
     */
    public function __construct(
        private iterable $listeners,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws Throwable the first delegate failure, relayed once every delegate was served; the
     *                   delegates are app-owned, so their failure type is unnameable here
     */
    public function committed(string $projection): void
    {
        $first = null;

        foreach ($this->listeners as $listener) {
            try {
                $listener->committed($projection);
            } catch (Throwable $e) {
                $first ??= $e;
            }
        }

        if ($first !== null) {
            throw $first;
        }
    }
}
