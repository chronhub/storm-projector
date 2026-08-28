<?php

declare(strict_types=1);

namespace Storm\Projector\Exception;

use LogicException;
use Storm\Projector\Store\ProjectionStatus;
use Storm\Projector\Store\ProjectionStore;

/**
 * Thrown when a compare-and-set transition is asked for with no expected source state.
 *
 * Empty could have meant "from anywhere", but that is exactly `forceStatus()` wearing a safer name; the
 * whole point of these methods is that the precondition travels INSIDE the statement. A caller with
 * genuinely nothing to compare against wants the unconditional setter and should say so.
 *
 * A LogicException: no runtime state produces this, only a call site that forgot its guard.
 *
 * @see ProjectionStore::pause()
 * @see ProjectionStore::resume()
 * @see ProjectionStore::requestStop()
 * @see ProjectionStore::retry()
 * @see ProjectionStore::forceStatus()
 */
final class InvalidStatusTransition extends LogicException
{
    public static function withoutExpectedStates(string $name, ProjectionStatus $to): self
    {
        return new self(sprintf(
            'Refusing to move projection "%s" to "%s" with no expected source state: a compare-and-set '
            .'without a comparison is an unconditional write. Pass the states the caller validated, or '
            .'call forceStatus() when overwriting unconditionally is genuinely the intent.',
            $name,
            $to->value,
        ));
    }
}
