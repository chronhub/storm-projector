<?php

declare(strict_types=1);

namespace Storm\Projector\Exception;

use LogicException;
use Storm\Projector\Definition\PersistentProjection;
use Storm\Projector\Definition\QueryProjection;
use Storm\Projector\Query\QueryProjectionRunner;
use Storm\Projector\Run\ProjectionRunner;

/**
 * Thrown when a projection is given to the wrong runtime: the checkpointed ProjectionRunner needs a
 * PersistentProjection, a ReadModel or LinkProjection; the QueryProjectionRunner needs a QueryProjection,
 * a checkpoint-less in-memory fold.
 *
 * @see ProjectionRunner
 */
final class UnsupportedProjection extends LogicException
{
    public static function notPersistent(string $name): self
    {
        return new self(sprintf(
            'Projection "%s" is not a PersistentProjection (ReadModel/LinkProjection); the checkpointed runner cannot run it. A QueryProjection runs on the QueryProjectionRunner.',
            $name,
        ));
    }

    public static function notQuery(string $name): self
    {
        return new self(sprintf(
            'Projection "%s" is not a QueryProjection; the query runtime only runs in-memory fold projections (a PersistentProjection runs on the checkpointed runner).',
            $name,
        ));
    }

    public static function invalidSelection(string $name): self
    {
        return new self(sprintf(
            'Projection "%s" must implement exactly one selection: FilteredProjection (categories/types) or DerivedStreamProjection (a derived stream).',
            $name,
        ));
    }

    public static function invalidGeneration(string $name, int $generation): self
    {
        return new self(sprintf(
            'Projection "%s" declares generation() = %d; it must be a positive int (0 is the unstamped sentinel — a 0 would silently disable the staleness gate).',
            $name,
            $generation,
        ));
    }
}
