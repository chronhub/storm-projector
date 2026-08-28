<?php

declare(strict_types=1);

namespace Storm\Projector\Run;

use Closure;
use Throwable;

/**
 * The per-batch onion: composes the stages so each wraps the next; the terminal returns the batch's
 * "seen" count of records fetched. A stage may short-circuit by returning without calling `$next`; e.g.
 * `Stage\ReadBatch` when nothing new is past the safe head. Built in code by `ProjectionRunner` so the
 * stage order is explicit.
 *
 * @see Stage\ReadBatch
 * @see ProjectionRunner
 */
final readonly class Pipeline
{
    /**
     * @param  list<Stage>  $stages
     */
    public function __construct(
        private array $stages,
    ) {}

    /**
     * @throws Throwable propagated from any stage in the chain
     */
    public function run(RunState $state): int
    {
        $terminal = static fn (RunState $s): int => count($s->records);

        $chain = array_reduce(
            array_reverse($this->stages),
            static fn (Closure $next, Stage $stage): Closure => static fn (RunState $s): int => $stage($s, $next),
            $terminal,
        );

        return $chain($state);
    }
}
