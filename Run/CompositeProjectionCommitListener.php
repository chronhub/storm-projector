<?php

declare(strict_types=1);

namespace Storm\Projector\Run;

use Storm\Contracts\Projector\ProjectionCommitListener;
use Storm\Projector\Telemetry\ListenerFailureContext;
use Storm\Projector\Telemetry\NullProjectorObservability;
use Storm\Projector\Telemetry\ProjectorObservability;
use Throwable;

/**
 * Fan-out of the post-commit hook: the port names ONE listener, the use case is plural, an HTTP
 * cache purge beside a metrics tick, so the bundle aliases the port to this composite over every
 * tagged implementation and installing one listener can never evict another.
 *
 * Each delegate is isolated: a throwing one, already a contract violation, must not starve its
 * siblings of a commit signal. The first failure is relayed AFTER every delegate was served, so
 * the runner's absorbing net still surfaces it through observability, never through the run's
 * outcome. Later failures are reported directly to the injected observability port, whose
 * fail-open contract preserves fan-out. The first failure is reported only by the runner.
 */
final readonly class CompositeProjectionCommitListener implements ProjectionCommitListener
{
    /**
     * @param  iterable<ProjectionCommitListener>  $listeners
     */
    public function __construct(
        private iterable $listeners,
        private ProjectorObservability $obs = new NullProjectorObservability,
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
                if ($first === null) {
                    $first = $e;
                } else {
                    $this->obs->recordListenerFailure(new ListenerFailureContext($projection, $e));
                }
            }
        }

        if ($first !== null) {
            throw $first;
        }
    }
}
