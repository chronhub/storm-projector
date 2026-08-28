<?php

declare(strict_types=1);

namespace Storm\Projector\Exception;

use RuntimeException;
use Storm\Projector\Definition\MigratedReadModel;

/**
 * Thrown at run start when a MigratedReadModel stands at checkpoint zero, no row or a reset one, while
 * its migration-owned table already holds rows.
 *
 * At checkpoint zero storm has folded nothing, so the content cannot be the checkpoint's own work: it is
 * stale. It is the trace of `forget`, which drops the tracking row but, by doctrine, leaves the table to
 * the migration, followed by the class coming back. Restarting at position 0 would refold the whole
 * history OVER that content, which an additive fold double-counts in silence: the same defect
 * `ProjectionManagement::delete()` closes from the other side by emptying the content storm owns.
 *
 * Refused, never repaired: whether the leftover rows should be truncated by hand or rebuilt via reset is
 * an operator's call, so the message names both exits.
 *
 * @see MigratedReadModel
 */
final class StaleMigratedContent extends RuntimeException
{
    public static function atCheckpointZero(string $name): self
    {
        return new self(sprintf(
            'Migrated read model "%s" is at checkpoint zero but its migration-owned table is not empty — '
            .'stale content from a forgotten checkpoint; replaying over it would double-count. '
            .'Clear the table yourself, or run `storm:projection:reset %s` to clear it and replay from scratch.',
            $name,
            $name,
        ));
    }
}
