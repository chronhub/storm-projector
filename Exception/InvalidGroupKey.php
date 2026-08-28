<?php

declare(strict_types=1);

namespace Storm\Projector\Exception;

use LogicException;
use Storm\Projector\Definition\GroupedReadModel;

/**
 * Thrown when a GroupedReadModel returns an empty-string grouping key: the skip channel is the empty
 * list, so an empty string is a malformed key extraction; failing loud beats silently starving a row.
 *
 * @see GroupedReadModel::groupKeys()
 */
final class InvalidGroupKey extends LogicException
{
    public static function empty(string $projection): self
    {
        return new self(sprintf(
            'Projection "%s" returned an empty-string group key; skip an event with an empty key LIST, never an empty key.',
            $projection,
        ));
    }
}
