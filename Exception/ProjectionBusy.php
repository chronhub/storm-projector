<?php

declare(strict_types=1);

namespace Storm\Projector\Exception;

use RuntimeException;

/**
 * Thrown when reset/delete is attempted on a projection that a worker is actively running, holding a
 * live lease; stop it first. The lease, not the status, is the source of truth for "running".
 */
final class ProjectionBusy extends RuntimeException
{
    public static function running(string $name): self
    {
        return new self(sprintf(
            'Projection "%s" is running (a worker holds the lease) — stop it before reset/delete.',
            $name,
        ));
    }
}
