<?php

declare(strict_types=1);

namespace Storm\Projector\Exception;

use InvalidArgumentException;
use Storm\Projector\Query\QueryRunOptions;
use Storm\Projector\Run\RunOptions;

/**
 * Thrown for an incoherent {@see RunOptions} or {@see QueryRunOptions} value: an out-of-range knob, a
 * `--to` target behind the persistent checkpoint, or, in the query lane, a `to` behind `from`.
 */
final class InvalidRunOptions extends InvalidArgumentException
{
    public static function notPositive(string $field, int $value): self
    {
        return new self(sprintf('%s must be a positive integer, got %d.', $field, $value));
    }

    public static function emptyOwner(): self
    {
        return new self('owner must be a non-empty string — it is the lease holder identity.');
    }

    public static function mustNotBeNegative(string $field, int $value): self
    {
        return new self(sprintf('%s must not be negative, got %d.', $field, $value));
    }

    public static function incoherentBackoffRange(int $min, int $max): self
    {
        return new self(sprintf('backoffMax (%d) must be >= backoffMin (%d).', $max, $min));
    }

    public static function daemonWithDrain(): self
    {
        return new self('Cannot combine --daemon with --drain: daemon runs forever, drain stops once caught up.');
    }

    public static function targetWithContinuousMode(): self
    {
        return new self('Cannot combine --to with --daemon or --drain: --to runs to a fixed position then stops.');
    }

    public static function targetBehindCheckpoint(string $name, int $to, int $checkpoint): self
    {
        return new self(sprintf(
            'Projection "%s" is already at position %d; --to=%d is behind the checkpoint (it would not run anything).',
            $name,
            $checkpoint,
            $to,
        ));
    }

    public static function targetBehindStart(int $from, int $to): self
    {
        return new self(sprintf('to (%d) must be >= from (%d) — the window would be empty or reversed.', $to, $from));
    }
}
