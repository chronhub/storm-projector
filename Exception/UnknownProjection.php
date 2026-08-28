<?php

declare(strict_types=1);

namespace Storm\Projector\Exception;

use InvalidArgumentException;
use Storm\Projector\Registry\ProjectionRegistry;

/**
 * Thrown when the ProjectionRegistry is asked for a name that no registered projection declares; a
 * configuration or typo error.
 *
 * @see ProjectionRegistry
 */
final class UnknownProjection extends InvalidArgumentException
{
    public static function named(string $name): self
    {
        return new self(sprintf(
            'No projection registered under the name "%s". Tag the projection class with #[AsProjection].',
            $name,
        ));
    }
}
