<?php

declare(strict_types=1);

namespace Storm\Projector\Exception;

use LogicException;
use Storm\Projector\Query\QueryFold;

/**
 * Thrown when a second terminal runs on the same {@see QueryFold}: a recipe is one-use, a terminal
 * consumes it, even when the run itself failed. Without the guard a second terminal silently re-folds
 * from scratch, which READS as a continuation, a second `once()` looking like page two or a `drain()`
 * after `follow()` like a refresh, but never is. Build a fresh recipe per invocation via
 * `QueryProjectionRunner::fold()`; it is cheap.
 */
final class FoldAlreadyRun extends LogicException
{
    public static function by(string $terminal, string $again, string $projection): self
    {
        return new self(sprintf(
            'This QueryFold on projection "%s" already ran %s(); a recipe is one-use and %s() would silently re-fold from scratch — build a fresh one via QueryProjectionRunner::fold().',
            $projection,
            $terminal,
            $again,
        ));
    }
}
