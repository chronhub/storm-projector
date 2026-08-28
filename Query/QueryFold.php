<?php

declare(strict_types=1);

namespace Storm\Projector\Query;

use Closure;
use Doctrine\DBAL\Exception;
use Storm\Chronicler\Query\EventFeedFilter;
use Storm\Chronicler\Record\EventRecord;
use Storm\Projector\Definition\QueryProjection;
use Storm\Projector\Exception\FoldAlreadyRun;
use Storm\Projector\Exception\InvalidRunOptions;
use Storm\Stream\StreamName;
use Throwable;

/**
 * Fluent, one-use recipe for the common query fold: hold the projection, scope the read, then run it
 * with an intent-named terminal, `once()`, `drain()` or `follow()`, instead of hand-assembling an
 * {@see \Storm\Chronicler\Query\EventFeedFilter} plus {@see QueryRunOptions} plus positional nulls. Built by
 * {@see QueryProjectionRunner::fold()}; the runner keeps owning the loop mechanics of safe-head
 * snapshot, paging and idle backoff, while this object only assembles the inputs and names the intent.
 *
 * ```php
 * $state = $runner->fold($this->projection)              // QueryProjection<T> yields typed T
 *     ->stream('account', $accountId)                    // one aggregate's stream
 *     ->events(...AccountOperation::EXPECTED_EVENTS)     // event classes, alias-resolved for you
 *     ->each($this->printRow(...))                       // optional per-event sink
 *     ->drain();                                         // fold to the safe head, then stop
 * ```
 *
 * The 90 % path, deliberately narrow: it selects exactly what {@see \Storm\Chronicler\Query\EventFeedFilter} can express, a
 * stream scope and an event set. An arbitrary selection, be it raw SQL, JSON predicates or a
 * caller-owned filter, stays on the runner's `run()` / `stream()` escape hatches; this recipe does not
 * grow knobs to chase them.
 *
 * Two traps the recipe removes: `events()` takes event classes and resolves the stored type strings
 * internally, so an `#[EventType]`-aliased event can no longer be silently missed by passing FQCNs to a
 * filter; and `follow(everyMs: 5000)` expresses a fixed poll without abusing the backoff triple of
 * `backoffMin = backoffMax, step 0`.
 *
 * One-use by design, and ENFORCED: the first terminal consumes the recipe; a second one throws
 * {@see FoldAlreadyRun} instead of silently re-folding from scratch, which would read as a continuation,
 * a second `once()` looking like page two when it never is. A recipe stays consumed even when its run
 * failed, so build a fresh one per invocation; the runner method is cheap. An invalid knob such as
 * `InvalidRunOptions` does NOT consume: fix the value and re-run the same recipe.
 *
 * @template TState
 */
final class QueryFold
{
    private ?StreamName $scope = null;

    /** @var list<class-string> */
    private array $events = [];

    /** @var positive-int */
    private int $pageSize = 1000;

    private int $after = 0;

    private ?int $upTo = null;

    /** @var (Closure(EventRecord): void)|null */
    private ?Closure $each = null;

    /** @var (Closure(): bool)|null */
    private ?Closure $stopWhen = null;

    /** The terminal that consumed this recipe, null while unused; the one-use guard's memory. */
    private ?string $consumedBy = null;

    /**
     * @param  QueryProjection<TState>  $projection
     */
    public function __construct(
        private readonly QueryProjectionRunner $runner,
        private readonly QueryProjection $projection,
    ) {}

    /**
     * Scope the read to one stream: `stream('account')` for a whole category, `stream('account', $id)`
     * for one aggregate. Unset, the fold reads every stream.
     *
     * @return $this
     */
    public function stream(string $category, ?string $qualifier = null): self
    {
        $name = new StreamName($category);
        $this->scope = $qualifier === null ? $name : $name->withQualifier($qualifier);

        return $this;
    }

    /**
     * Scope the read with an already-built {@see \Storm\Stream\StreamName}, the VO-first pendant of `stream()`.
     *
     * @return $this
     */
    public function scope(StreamName $scope): self
    {
        $this->scope = $scope;

        return $this;
    }

    /**
     * Keep only these event classes. Resolved to their stored type strings internally, alias- and
     * version-aware; pass the FQCNs, never pre-resolved strings. An empty set, the default, keeps
     * every type.
     *
     * @param  class-string  ...$eventClasses
     * @return $this
     */
    public function events(string ...$eventClasses): self
    {
        $this->events = array_values($eventClasses);

        return $this;
    }

    /**
     * Events per page; the terminal's {@see QueryRunOptions} validates it `> 0`. Default 1000.
     *
     * @param  positive-int  $events
     * @return $this
     */
    public function pageSize(int $events): self
    {
        $this->pageSize = $events;

        return $this;
    }

    /**
     * Start the scan after this position, exclusive. Default 0, the whole store.
     *
     * @return $this
     */
    public function after(int $position): self
    {
        $this->after = $position;

        return $this;
    }

    /**
     * Cap the scan at this position, inclusive; never past the safe head regardless.
     *
     * @return $this
     */
    public function upTo(int $position): self
    {
        $this->upTo = $position;

        return $this;
    }

    /**
     * Per-event sink, called after the projection folded the record, e.g. a console writer that reads
     * the projection's running state per line. Unset, the fold is silent.
     *
     * @param  Closure(EventRecord): void  $sink
     * @return $this
     */
    public function each(Closure $sink): self
    {
        $this->each = $sink;

        return $this;
    }

    /**
     * Polled after each page; return true for a graceful stop; a console wires it to a
     * SIGTERM/SIGINT handler.
     *
     * @param  callable(): bool  $shouldStop
     * @return $this
     */
    public function stopWhen(callable $shouldStop): self
    {
        $this->stopWhen = $shouldStop(...);

        return $this;
    }

    /**
     * Fold a single page of `pageSize` events, then stop; a bounded peek.
     *
     * @return TState
     *
     * @throws FoldAlreadyRun when this recipe already ran a terminal; build a fresh one
     * @throws InvalidRunOptions on an out-of-range recipe value, e.g. a non-positive page size
     * @throws Exception on a DBAL failure of the underlying read
     * @throws Throwable propagated from the projection's `apply()`, a fold error, or the `each()` sink
     */
    public function once(): mixed
    {
        return $this->execute(QueryRunMode::Once);
    }

    /**
     * Fold up to the safe-head snapshot, paged, then stop; the current answer.
     *
     * @return TState
     *
     * @throws FoldAlreadyRun when this recipe already ran a terminal; build a fresh one
     * @throws InvalidRunOptions on an out-of-range recipe value, e.g. a non-positive page size
     * @throws Exception on a DBAL failure of the underlying read
     * @throws Throwable propagated from the projection's `apply()`, a fold error, or the `each()` sink
     */
    public function drain(): mixed
    {
        return $this->execute(QueryRunMode::Drain);
    }

    /**
     * Drain, then keep folding new events as they arrive, until `stopWhen()` or `$maxRuntime`.
     *
     * `$everyMs` null polls adaptively via the runner's growing idle backoff; a value pins a fixed poll
     * interval, so `follow(everyMs: 5000)` is "check every five seconds".
     *
     * @param  int|null  $everyMs  fixed poll interval in milliseconds; null for adaptive backoff
     * @param  int|null  $maxRuntime  wall-clock budget in seconds; null runs until stopped
     * @return TState
     *
     * @throws FoldAlreadyRun when this recipe already ran a terminal; build a fresh one
     * @throws InvalidRunOptions on an out-of-range recipe value, e.g. a non-positive poll interval
     * @throws Exception on a DBAL failure of the underlying read
     * @throws Throwable propagated from the projection's `apply()`, a fold error, or the `each()` sink
     */
    public function follow(?int $everyMs = null, ?int $maxRuntime = null): mixed
    {
        return $this->execute(QueryRunMode::Daemon, $everyMs, $maxRuntime);
    }

    /**
     * @return TState
     *
     * @throws FoldAlreadyRun
     * @throws InvalidRunOptions
     * @throws Exception
     * @throws Throwable
     */
    private function execute(QueryRunMode $mode, ?int $everyMs = null, ?int $maxRuntime = null): mixed
    {
        $terminal = match ($mode) {
            QueryRunMode::Once => 'once',
            QueryRunMode::Drain => 'drain',
            QueryRunMode::Daemon => 'follow',
        };

        if ($this->consumedBy !== null) {
            throw FoldAlreadyRun::by($this->consumedBy, $terminal, $this->projection->name());
        }

        $options = new QueryRunOptions(
            mode: $mode,
            batch: $this->pageSize,
            from: $this->after,
            to: $this->upTo,
            maxRuntime: $maxRuntime,
            backoffMin: $everyMs ?? 200,
            backoffMax: $everyMs ?? 1000,
            backoffStep: $everyMs === null ? 100 : 0,
        );

        // consumed from here on, even if the run fails: a half-run recipe is not resumable; a re-run
        // would restart from scratch, exactly the silent surprise this guard exists to prevent. An
        // invalid knob above did NOT consume: fix the value, re-run the same recipe.
        $this->consumedBy = $terminal;

        $filter = new EventFeedFilter(
            scope: $this->scope,
            types: $this->events === [] ? null : $this->runner->resolveTypes(...$this->events),
        );

        return $this->runner->streamProjection($this->projection, $filter, $options, $this->stopWhen, $this->each);
    }
}
