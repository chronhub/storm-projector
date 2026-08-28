<?php

declare(strict_types=1);

namespace Storm\Projector\Exception;

use RuntimeException;
use Storm\Projector\Store\ProjectionStatus;

/**
 * Thrown when `retry` is attempted on a projection not in the `failed` status; `retry` only recovers a
 * failed projection, clearing the failure and resuming from the checkpoint. A non-failed one has nothing
 * to retry; a full rebuild is `reset`.
 */
final class ProjectionNotFailed extends RuntimeException
{
    public static function cannotRetry(string $name, ?ProjectionStatus $status): self
    {
        return new self(sprintf(
            'Projection "%s" is "%s", not failed — retry only recovers a failed projection (use reset to replay from scratch).',
            $name,
            $status === null ? 'never run' : $status->value,
        ));
    }
}
