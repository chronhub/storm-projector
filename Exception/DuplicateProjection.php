<?php

declare(strict_types=1);

namespace Storm\Projector\Exception;

use LogicException;
use Storm\Projector\Definition\Projection;

/**
 * Thrown at registry construction when two projection services declare the same `Projection::name()`;
 * names are the registry/checkpoint key and must be unique.
 */
final class DuplicateProjection extends LogicException
{
    /**
     * @param  class-string  $first
     * @param  class-string  $second
     */
    public static function name(string $name, string $first, string $second): self
    {
        return new self(sprintf(
            'Two projections claim the name "%s": %s and %s. Projection names must be unique.',
            $name,
            $first,
            $second,
        ));
    }

    public static function splitAcrossHomes(string $name): self
    {
        return new self(sprintf(
            'Projection "%s" has a row on BOTH database homes — it lives on exactly one, so a row on each means its kind (link-producer vs read-model) changed without a state-home migration. Two binaries can run it under independent leases; migrate the stale home or delete its row.',
            $name,
        ));
    }
}
