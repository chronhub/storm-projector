<?php

declare(strict_types=1);

namespace Storm\Projector\Run\Stage;

use Closure;
use Storm\Projector\Run\ProjectionEventSource;
use Storm\Projector\Run\ProjectionReadWindow;
use Storm\Projector\Run\RunState;
use Storm\Projector\Run\Stage;

/**
 * Reads the next batch through the event source, bounded by its watermark so a projection never
 * advances past an in-flight position nor past its producer's link head. Short-circuits, returning
 * 0, when nothing new is past the checkpoint. Otherwise, it continues even if no events matched the
 * selection, so `Checkpoint` advances past the scanned non-matching range, and the projector does
 * not re-scan it forever.
 *
 * The stage owns only the run's rules: the `--to` cap, the caught-up short-circuit, and where the
 * page lands in the run state. How the frontier is probed and how the selection becomes a store
 * query belong to the source.
 *
 * @see Checkpoint
 */
final readonly class ReadBatch implements Stage
{
    public function __construct(
        private ProjectionEventSource $source,
    ) {}

    public function __invoke(RunState $state, Closure $next): int
    {
        // the frontier is re-derived per cycle; timed so the probe's cost stays visible
        $probeStart = microtime(true);
        $max = $this->source->watermark($state->profile->sourceStream);
        // @infection-ignore-all; equivalent-in-unit: wall-clock telemetry arithmetic, no deterministic reader below the integration suite
        $state->watermarkMs = (microtime(true) - $probeStart) * 1000;

        $state->scanHead = $max; // the reachable head before the `--to` cap, for the lag metric, set even when caught up

        // a `--to` target caps the read further, still never past the frontier
        if ($state->profile->options->to !== null) {
            $max = min($max, $state->profile->options->to);
        }

        if ($max <= $state->lastPosition) {
            return 0; // caught up to the frontier or the target, nothing new to do
        }

        $state->scanMax = $max;

        $state->records = $this->source->read(new ProjectionReadWindow(
            after: $state->lastPosition,
            max: $max,
            limit: $state->profile->options->batch,
            categories: $state->profile->categories,
            types: $state->profile->types,
            sourceStream: $state->profile->sourceStream,
        ));

        return $next($state);
    }
}
