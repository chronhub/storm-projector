<?php

declare(strict_types=1);

namespace Storm\Projector\Query;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Storm\Chronicler\Query\MutableQueryFilter;
use Storm\Chronicler\Query\QueryFilter;
use Storm\Chronicler\Record\EventRecord;
use Storm\Chronicler\Store\StreamReader;
use Storm\Contracts\Chronicler\EventTypeMapper;
use Storm\Projector\Definition\QueryProjection;
use Storm\Projector\Exception\UnknownProjection;
use Storm\Projector\Exception\UnsupportedProjection;
use Storm\Projector\Registry\ProjectionRegistry;
use Storm\Projector\Run\FilterTypes;
use Storm\Projector\Run\ProjectionRunner;
use Throwable;

/**
 * Runs a QueryProjection checkpoint-less: folds the events a caller-supplied filter matches into the
 * projection's in-memory state and returns `getState()`. No checkpoint, no lease, no DB write; the state
 * is ephemeral. Two entry points, one for each shape of read:
 *
 * - `run()` folds any pre-bounded `QueryFilter` once. The caller's filter owns the whole read via its
 *   own `LIMIT`; the runner folds the Generator lazily and resets first, so it folds from scratch every
 *   time. This is the fold for an arbitrary selection: a {@see \Storm\Chronicler\Query\ProjectionFilter},
 *   or LiveQuery's `InspectFilter` from the optional `storm-live-query` companion package for a JSON
 *   `header` predicate, raw SQL or recipe, or the caller's own filter.
 *
 * - `stream()` is the managed run, once/drain/daemon via a {@see QueryRunOptions}, the read-only pendant
 *   of the persistent {@see ProjectionRunner}. The runner owns the loop, the safe-head snapshot, paging,
 *   idle backoff, `maxRuntime` and graceful stop, and re-windows a {@see \Storm\Chronicler\Query\MutableQueryFilter} to
 *   `(cursor, safeHead]` per page. The caller owns only the selection, the filter, and the loop policy,
 *   the options; no hand-rolled daemon loop in app code.
 *
 * App-facing sugar: `fold()` starts a {@see QueryFold}, the fluent recipe over `streamProjection()` for
 * the common shape: one projection instance, a stream scope, an event-class set, and an intent-named
 * terminal, one of `once`, `drain` or `follow`. Prefer it in app code; the entry points below are the
 * engine and the escape hatch for an arbitrary filter.
 *
 * Every entry point exists in two forms: by registry `$name`, for a console command resolving user
 * input, and by {@see QueryProjection} instance, for an app that already holds the service: no string
 * round-trip, no registry lookup, and the folded state comes back typed via the interface's `TState`
 * template.
 *
 * The caller owns the query. The runner does not constrain the filter; a checkpoint-less fold has none
 * to protect, so overprotecting it would just push devs to bypass the framework. `stream()` needs a
 * windowed filter only because it must re-bound the read to page it safely.
 *
 * An optional `$output` callback receives each folded `EventRecord` as it streams by, letting a console
 * command write the events while it computes; `null` is a silent fold. Per-run on purpose: the sink
 * belongs to the invocation, the same wired runner serving a silent dashboard read and a verbose console
 * command, not to the service.
 */
final readonly class QueryProjectionRunner
{
    public function __construct(
        private ProjectionRegistry $registry,
        private StreamReader $streamReader,
        private Connection $connection,
        private EventTypeMapper $eventTypeMapper,
    ) {}

    /**
     * Run a QueryProjection, folding the events matching the filter into its in-memory state.
     *
     * @param  (Closure(EventRecord): void)|null  $output  optional per-event sink, e.g. a console writer;
     *                                                     `null` is silent
     *
     * @throws UnknownProjection when no projection is registered under `$name`
     * @throws UnsupportedProjection when the named projection is not a QueryProjection
     * @throws Exception on a DBAL failure of the underlying read
     * @throws Throwable propagated from the projection's `apply()`, a fold error, or the `$output` sink
     */
    public function run(string $name, QueryFilter $filter, ?Closure $output = null): mixed
    {
        return $this->runProjection($this->queryProjection($name), $filter, $output);
    }

    /**
     * `run()` by instance: fold the filter's read into a projection the caller already holds, with no
     * registry lookup, and the state comes back as the projection's declared `TState`.
     *
     * @template T
     *
     * @param  QueryProjection<T>  $projection
     * @param  (Closure(EventRecord): void)|null  $output  optional per-event sink; `null` is silent
     * @return T
     *
     * @throws Exception on a DBAL failure of the underlying read
     * @throws Throwable propagated from the projection's `apply()`, a fold error, or the `$output` sink
     */
    public function runProjection(QueryProjection $projection, QueryFilter $filter, ?Closure $output = null): mixed
    {
        $projection->reset(); // fold from scratch; state is in-memory

        $cursor = 0;
        $this->foldOnce($projection, $filter, $output, $cursor);

        return $projection->getState();
    }

    /**
     * Managed run, once/drain/daemon, the read-only pendant of {@see ProjectionRunner::run()}.
     *
     * The loop snapshots the safe head, capped by `to`, pages the window `(cursor, head]` at `batch`,
     * where a full page means the window holds more, and per `mode`:
     * - Once: one page, then stop.
     *
     * - Drain: page until caught up to the head snapshot, then stop.
     *
     * - Daemon: drain, then re-snapshot the head each cycle and fold new windows, idle-backoff when
     *   nothing arrived, until `$shouldStop` or `maxRuntime`.
     *
     * Read-only: no lease, no persisted checkpoint, always folds from scratch, so a restart re-folds. The
     * daemon contains the drain: it pages the window to the head snapshot, the drain, then keeps polling,
     * so there is no drain-then-daemon handoff for a caller to stitch, and no need to know the safe head,
     * the runner owns it.
     *
     * @param  (callable(): bool)|null  $shouldStop  polled after each page; a console wires it to a
     *                                               SIGTERM/SIGINT handler for a graceful daemon stop
     * @param  (Closure(EventRecord): void)|null  $output  optional per-event sink; `null` is silent
     *
     * @throws UnknownProjection when no projection is registered under `$name`
     * @throws UnsupportedProjection when the named projection is not a QueryProjection
     * @throws Exception on a DBAL failure of the underlying read
     * @throws Throwable propagated from the projection's `apply()`, a fold error, or the `$output` sink
     */
    public function stream(
        string $name,
        MutableQueryFilter $filter,
        QueryRunOptions $options,
        ?callable $shouldStop = null,
        ?Closure $output = null,
    ): mixed {
        return $this->streamProjection($this->queryProjection($name), $filter, $options, $shouldStop, $output);
    }

    /**
     * `stream()` by instance: the managed once/drain/daemon run, on a caller-held projection like
     * `runProjection()`.
     *
     * @template T
     *
     * @param  QueryProjection<T>  $projection
     * @param  (callable(): bool)|null  $shouldStop  polled after each page; true stops gracefully
     * @param  (Closure(EventRecord): void)|null  $output  optional per-event sink; `null` is silent
     * @return T
     *
     * @throws Exception on a DBAL failure of the underlying read
     * @throws Throwable propagated from the projection's `apply()`, a fold error, or the `$output` sink
     */
    public function streamProjection(
        QueryProjection $projection,
        MutableQueryFilter $filter,
        QueryRunOptions $options,
        ?callable $shouldStop = null,
        ?Closure $output = null,
    ): mixed {
        $projection->reset(); // fold from scratch; state is in-memory

        $cursor = $options->from;
        $idleMs = $options->backoffMin;
        $startedAt = microtime(true);
        $head = $this->boundedHead($options->to);

        do {
            $filter->window($cursor, $head, $options->batch);
            $seen = $this->foldOnce($projection, $filter, $output, $cursor);

            if (($shouldStop !== null && $shouldStop()) || $this->maxRuntimeReached($options, $startedAt)) {
                break; // graceful stop by signal, or runtime budget exhausted
            }

            if ($options->mode === QueryRunMode::Once) {
                break; // a single page
            }

            if ($seen === $options->batch) {
                continue; // a full page, so the window may hold more, keep paging
            }

            if ($options->mode === QueryRunMode::Drain) {
                break; // a non-full page means caught up to the head snapshot
            }

            // A non-full page is the PROOF that `(cursor, head]` is exhausted: the filter returned
            // everything it matched inside it. So the cursor may skip to that head, and MUST; it only
            // advances on a yielded record, so without the skip a selective follow() that matches nothing
            // would keep its original lower bound while the head climbs on other streams, re-scanning an
            // ever-growing historical interval on every single poll. Done against the head this page was
            // READ under, never the fresher one taken below.
            $cursor = max($cursor, $head);

            // Daemon, non-full page: idle-sleep when this page folded nothing FOR THIS FILTER, `seen === 0`;
            // the global head may keep advancing on other streams, so `head` is the wrong idle signal for a
            // selective filter, since it would busy-spin. A productive page resets the backoff. Then re-snapshot the
            // head so the next window covers whatever has since arrived.
            if ($seen === 0) {
                usleep($idleMs * 1000);
                $idleMs = min($options->backoffMax, $idleMs + $options->backoffStep);
            } else {
                $idleMs = $options->backoffMin;
            }
            $head = $this->boundedHead($options->to);
        } while (true);

        return $projection->getState();
    }

    /**
     * Start a {@see QueryFold} over `streamProjection()`. One recipe per invocation, it is not
     * reusable.
     *
     * @template T
     *
     * @param  QueryProjection<T>  $projection
     * @return QueryFold<T>
     */
    public function fold(QueryProjection $projection): QueryFold
    {
        return new QueryFold($this, $projection);
    }

    /**
     * Expand event classes to the type strings they are stored under, alias- and version-aware, then feed
     * the result to a filter's type list so an `#[EventType]`-aliased event is not silently missed, since
     * the DB stores the alias, not the FQCN. Opt-in: `run()` does not resolve for you, the caller owns the
     * filter.
     *
     * @param  class-string  ...$classes
     * @return list<string> the stored types, deduplicated; empty in, empty out
     */
    public function resolveTypes(string ...$classes): array
    {
        return FilterTypes::resolve($this->eventTypeMapper, array_values($classes));
    }

    /**
     * @return QueryProjection<mixed>
     *
     * @throws UnknownProjection when no projection is registered under `$name`
     * @throws UnsupportedProjection when the named projection is not a QueryProjection
     */
    private function queryProjection(string $name): QueryProjection
    {
        $projection = $this->registry->get($name);

        if (! $projection instanceof QueryProjection) {
            throw UnsupportedProjection::notQuery($name);
        }

        return $projection;
    }

    /**
     * The scan's upper bound: the safe-head snapshot, where `0` means an empty store and never past an
     * in-flight position, capped by `$to` when the caller set one.
     */
    private function boundedHead(?int $to): int
    {
        $safe = $this->streamReader->safeHeadPosition()?->toOrdinal() ?? 0;

        return $to === null ? $safe : min($to, $safe);
    }

    private function maxRuntimeReached(QueryRunOptions $options, float $startedAt): bool
    {
        return $options->maxRuntime !== null && (microtime(true) - $startedAt) >= $options->maxRuntime;
    }

    /**
     * Fold the filter's read once into the projection, streaming each record to `$output` and advancing
     * `$cursor` to the highest position seen. Returns the number of events folded, the page size the
     * paging loop tests against `batch`.
     *
     * @param  QueryProjection<mixed>  $projection
     * @param  (Closure(EventRecord): void)|null  $output
     *
     * @throws Throwable propagated from the projection's `apply()` or the `$output` sink
     */
    private function foldOnce(QueryProjection $projection, QueryFilter $filter, ?Closure $output, int &$cursor): int
    {
        $seen = 0;

        foreach ($this->streamReader->retrieveByFilter($filter) as $record) {
            $projection->apply($record, $this->connection);
            $cursor = max($cursor, $record->position->toOrdinal());

            if ($output !== null) {
                $output($record);
            }

            $seen++;
        }

        return $seen;
    }
}
