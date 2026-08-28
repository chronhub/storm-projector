<?php

declare(strict_types=1);

namespace Storm\Projector\Tests\Fixture;

use Doctrine\DBAL\Connection;
use Storm\Chronicler\Record\EventRecord;
use Storm\Projector\Definition\LinkProjection;
use Storm\Stream\StreamName;

/**
 * A minimal LinkProjection SHAPE for the homing tests; only the interface hierarchy matters, since
 * ProjectionHome::of keys on it, and nothing here ever runs.
 */
final readonly class StubLinkProjection implements LinkProjection
{
    public function __construct(
        private string $name = 'stub_link',
        private string $target = 'stub-link-target',
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

    public function targetStream(): StreamName
    {
        return new StreamName($this->target);
    }

    public function initialize(Connection $tx): void {}

    public function clear(Connection $tx): void {}

    public function drop(Connection $tx): void {}

    public function apply(EventRecord $event, Connection $tx): bool
    {
        return true;
    }
}
