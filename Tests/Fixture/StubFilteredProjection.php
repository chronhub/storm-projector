<?php

declare(strict_types=1);

namespace Storm\Projector\Tests\Fixture;

use Doctrine\DBAL\Connection;
use Storm\Chronicler\Record\EventRecord;
use Storm\Projector\Definition\FilteredProjection;
use Throwable;

/**
 * A minimal filtered persistent projection for runner unit tests: reads all categories/types,
 * generation 1, and every output-lifecycle method is a no-op, there being no real table. `apply` is
 * never reached in these tests; the failure is injected upstream at AcquireCheckpoint, or at
 * `initialize()` via `$initializeThrows`, the pre-loop output-mounting failure.
 */
final readonly class StubFilteredProjection implements FilteredProjection
{
    public function __construct(
        private string $name,
        private ?Throwable $initializeThrows = null,
        private int $generation = 1,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function categories(): array
    {
        return [];
    }

    public function eventTypes(): array
    {
        return [];
    }

    public function generation(): int
    {
        return $this->generation;
    }

    public function initialize(Connection $tx): void
    {
        if ($this->initializeThrows !== null) {
            throw $this->initializeThrows;
        }
    }

    public function clear(Connection $tx): void {}

    public function drop(Connection $tx): void {}

    public function apply(EventRecord $event, Connection $tx): bool
    {
        return true;
    }
}
