<?php

declare(strict_types=1);

namespace Storm\Projector\Run\Stage;

use Closure;
use Doctrine\DBAL\Exception;
use Storm\Projector\Run\RunState;
use Storm\Projector\Run\Stage;
use Storm\Projector\Store\ProjectionRunStore;

/**
 * Advances the checkpoint atomically with the applied events, still in the batch transaction.
 *
 * - Batch full, `count(records) >= batch`: more matching events may remain in the scanned range, so
 *   advance only to the last record applied; the next cycle continues from there.
 *
 * - Batch not full, including zero matches: the matching events up to the safe head are exhausted, so
 *   advance to `scanMax`, skipping any non-matching tail so the projector never re-scans it.
 *
 * Advancing to `scanMax`, the safe-head rather than the max row actually read, is sound. The safe-head
 * holds below the first young, possibly in-flight, `sequence_no` gap, so `scanMax` can never sit above an
 * invisible in-flight row. The one residual: a transaction in-flight past the grace window is presumed
 * aborted and stepped over by the safe-head machinery, pathological for storm's millisecond appends.
 */
final readonly class Checkpoint implements Stage
{
    public function __construct(
        private ProjectionRunStore $store,
    ) {}

    /**
     * @throws Exception on a DBAL failure of the checkpoint advance
     */
    public function __invoke(RunState $state, Closure $next): int
    {
        $batchFull = count($state->records) >= $state->profile->options->batch;
        $advanceTo = $batchFull ? $state->lastApplied : $state->scanMax;

        $this->store->advance($state->profile->name, $advanceTo, $state->profile->options->owner);
        $state->lastPosition = $advanceTo;

        return $next($state);
    }
}
