<?php

declare(strict_types=1);

namespace Storm\Projector\Tests\Fixture;

use Doctrine\DBAL\Connection;
use Storm\Chronicler\Record\EventRecord;
use Storm\Projector\Definition\PersistentProjection;
use Storm\Projector\Definition\Projection;

/**
 * Minimal Projection for registry unit tests: just a name, and `apply` is a no-op. Selection via
 * `categories`/`eventTypes` lives on PersistentProjection, not the base, so a bare `Projection`
 * fixture doesn't carry it.
 *
 * @see PersistentProjection
 */
final readonly class FakeProjection implements Projection
{
    public function __construct(
        private string $name,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function apply(EventRecord $event, Connection $tx): bool
    {
        return true;
    }
}
