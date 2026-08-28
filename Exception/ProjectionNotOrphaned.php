<?php

declare(strict_types=1);

namespace Storm\Projector\Exception;

use RuntimeException;
use Storm\Projector\Registry\ProjectionRegistry;

/**
 * Thrown when `forget` is attempted on a projection whose code is still registered, still in the
 * ProjectionRegistry via `#[AsProjection]`. `forget` is only for orphans, a `projections` row whose
 * class was removed; use `delete` for a live projection, since it resolves the instance to drop the
 * read-model table or link rows properly.
 *
 * @see ProjectionRegistry
 */
final class ProjectionNotOrphaned extends RuntimeException
{
    public static function stillRegistered(string $name): self
    {
        return new self(sprintf(
            'Projection "%s" is still registered (its code exists) — use delete, not forget. '
            .'forget is only for an orphaned row whose class was removed.',
            $name,
        ));
    }
}
