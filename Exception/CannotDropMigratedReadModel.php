<?php

declare(strict_types=1);

namespace Storm\Projector\Exception;

use RuntimeException;
use Storm\Projector\Definition\MigratedReadModel;

/**
 * Thrown when `drop()` is called on a MigratedReadModel. Its table's schema is owned by a migration
 * upstream, so storm must not drop it; dropping it would desync the migration's version table, which
 * still believes the table exists, leaving a later `migrate` a no-op. Drop it via your migration's
 * `down()`. Note `delete` does NOT hit this: it detects the marker and removes only the checkpoint row,
 * leaving the table to the migration, so this exception is the safety net for a direct call.
 *
 * @see MigratedReadModel
 */
final class CannotDropMigratedReadModel extends RuntimeException
{
    public static function forTable(string $table): self
    {
        return new self(sprintf(
            'Storm will not drop migrated read-model table "%s" — its schema is owned by a migration. Drop it via your migration down(); use delete to remove only the projection checkpoint.',
            $table,
        ));
    }
}
