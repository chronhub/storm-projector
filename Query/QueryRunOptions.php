<?php

declare(strict_types=1);

namespace Storm\Projector\Query;

use Storm\Projector\Exception\InvalidRunOptions;
use Storm\Projector\Run\RunOptions;

/**
 * Run-time knobs for one {@see QueryProjectionRunner::stream()}, the read-only pendant of
 * {@see RunOptions}. Immutable value. Deliberately carries none of RunOptions' coordination fields,
 * `owner`, `leaseTtl` and `allowStale`: a query fold holds no lease and has no generation, so those
 * concepts do not exist here, and reusing RunOptions would drag dead fields into the lane and lie about
 * coordination.
 *
 * The filter carries the selection, a {@see \Storm\Chronicler\Query\MutableQueryFilter}; this object carries
 * the loop policy. Nothing here shapes SQL, it governs how many times and how often the runner folds. The
 * split mirrors the persistent lane, RunOptions versus the projection's declared selection.
 *
 * - `from`/`to` bound the scan: `from` is the exclusive start, `0` for the whole store, and `to` the
 *   inclusive cap, `null` for the safe-head snapshot. Never past the safe head regardless of `to`.
 *
 * - `batch` is the page size, the filter's LIMIT; a full page means the window may hold more.
 *
 * - `maxRuntime` stops a daemon after N wall-clock seconds; `backoff*` is the idle delay that grows from
 *   `backoffMin` to `backoffMax` by `backoffStep` each empty daemon cycle.
 */
final readonly class QueryRunOptions
{
    /**
     * @param  positive-int  $batch  events per page; still validated `> 0` at construction, so every
     *                               caller is guarded, not only the phpstan-checked path
     * @param  int  $from  exclusive start position; validated `>= 0`
     * @param  int|null  $to  inclusive max position; validated `> 0` and `>= from` when set
     * @param  int|null  $maxRuntime  daemon wall-clock budget in seconds; validated `>= 0` when set
     *
     * @throws InvalidRunOptions on an out-of-range value: a non-positive batch, to or backoffMin, a
     *                           negative from, backoffStep or maxRuntime, backoffMax below backoffMin,
     *                           or `to` behind `from`
     */
    public function __construct(
        public QueryRunMode $mode = QueryRunMode::Once,
        public int $batch = 1000,
        public int $from = 0,
        public ?int $to = null,
        public ?int $maxRuntime = null,
        public int $backoffMin = 200,
        public int $backoffMax = 1000,
        public int $backoffStep = 100,
    ) {
        // phpstan deems this dead under the very phpdoc type it defends; the runtime check is the guard
        // @phpstan-ignore smallerOrEqual.alwaysFalse
        if ($batch <= 0) {
            throw InvalidRunOptions::notPositive('batch', $batch);
        }

        if ($from < 0) {
            throw InvalidRunOptions::mustNotBeNegative('from', $from);
        }

        if ($to !== null && $to <= 0) {
            throw InvalidRunOptions::notPositive('to', $to);
        }

        if ($to !== null && $to < $from) {
            throw InvalidRunOptions::targetBehindStart($from, $to);
        }

        if ($backoffMin <= 0) {
            throw InvalidRunOptions::notPositive('backoffMin', $backoffMin);
        }

        if ($backoffStep < 0) {
            throw InvalidRunOptions::mustNotBeNegative('backoffStep', $backoffStep);
        }

        if ($backoffMax < $backoffMin) {
            throw InvalidRunOptions::incoherentBackoffRange($backoffMin, $backoffMax);
        }

        if ($maxRuntime !== null && $maxRuntime < 0) {
            throw InvalidRunOptions::mustNotBeNegative('maxRuntime', $maxRuntime);
        }
    }
}
