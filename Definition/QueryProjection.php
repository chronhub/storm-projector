<?php

declare(strict_types=1);

namespace Storm\Projector\Definition;

use Storm\Projector\Query\QueryProjectionRunner;

/**
 * A projection that folds events into in-memory state, with no DB target. Filtered mode. Unlike a
 * one-shot LiveQuery, it is daemon-capable: it can run continuously to keep a live-computed value or
 * drive reactions. On a daemon restart its state is re-folded. `apply()` ignores the connection.
 *
 * Generic over the folded state: declare `@implements QueryProjection<array{ops: int, balance: int}>`
 * matching the state shape, so a typed fold returns that shape instead of `mixed` and callers stop
 * re-asserting it with `@var` casts. The typed fold entry points are {@see QueryProjectionRunner::fold()},
 * `runProjection()` and `streamProjection()`.
 *
 * @template TState
 */
interface QueryProjection extends Projection
{
    /**
     * The current folded state.
     *
     * @return TState
     */
    public function getState(): mixed;

    /**
     * Clear the folded state so the next run folds from scratch.
     */
    public function reset(): void;
}
