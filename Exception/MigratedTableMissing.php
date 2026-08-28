<?php

declare(strict_types=1);

namespace Storm\Projector\Exception;

use RuntimeException;
use Storm\Projector\Definition\MigratedReadModel;

/**
 * Thrown at projection start when a MigratedReadModel's table is absent. The table is owned by a
 * migration upstream, storm never creating it, so a missing table means the migration has not been run;
 * a fail-fast at `initialize()` is clearer than a cryptic "relation does not exist" on the first fold.
 * Run your migrations before starting the projection.
 *
 * @see MigratedReadModel
 */
final class MigratedTableMissing extends RuntimeException
{
    public static function forTable(string $table): self
    {
        return new self(sprintf(
            'Migrated read-model table "%s" does not exist — its schema is owned by a migration; run your migrations before starting the projection.',
            $table,
        ));
    }
}
