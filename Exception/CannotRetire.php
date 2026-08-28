<?php

declare(strict_types=1);

namespace Storm\Projector\Exception;

use RuntimeException;
use Storm\Projector\Definition\LinkProjection;

/**
 * Thrown when `retire` is misused. `retire` decommissions a stream producer, a `LinkProjection` or a
 * `FanOutLinkProjection`, while keeping its derived stream for consumers, detaching the producer, as
 * opposed to `delete` which drops the stream, dropping the product. So it is rejected when:
 *
 * - The target is not a stream producer, so there is nothing to orphan; use `delete` for a read model.
 *
 * - The orphaning is not acknowledged; leaving a stream producer-less is deliberate, so pass
 *   `--orphan-stream`.
 */
final class CannotRetire extends RuntimeException
{
    public static function notAStreamProducer(string $name): self
    {
        return new self(sprintf(
            'Projection "%s" is not a stream producer — only a producer can be retired with its stream kept. Use delete for a read model.',
            $name,
        ));
    }

    public static function streamNotAcknowledged(string $name): self
    {
        return new self(sprintf(
            'Retiring "%s" leaves its derived stream with no producer (an orphan). Pass --orphan-stream to acknowledge, or use delete to drop the stream instead.',
            $name,
        ));
    }
}
