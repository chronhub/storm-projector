<?php

declare(strict_types=1);

namespace Storm\Projector\Tests\Fixture;

use Doctrine\DBAL\Connection;
use Storm\Chronicler\Record\EventRecord;
use Storm\Projector\Definition\FanOutLinkProjection;
use Storm\Stream\StreamName;

/**
 * A minimal FanOutLinkProjection SHAPE for the homing tests; the fan-out is a link producer that is
 * NOT a LinkProjection subtype, so the home rule must name it explicitly.
 */
final readonly class StubFanOutProjection implements FanOutLinkProjection
{
    public function __construct(
        private string $name = 'stub_fan_out',
        private string $prefix = 'stub-fan-',
        private ?StreamName $target = null,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function generation(): int
    {
        return 1;
    }

    public function categories(): array
    {
        return ['account'];
    }

    public function eventTypes(): array
    {
        return [];
    }

    public function targetFor(EventRecord $event): ?StreamName
    {
        return $this->target;
    }

    public function targetPrefix(): string
    {
        return $this->prefix;
    }

    public function initialize(Connection $tx): void {}

    public function clear(Connection $tx): void {}

    public function drop(Connection $tx): void {}

    public function apply(EventRecord $event, Connection $tx): bool
    {
        return true;
    }
}
