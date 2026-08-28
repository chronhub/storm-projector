<?php

declare(strict_types=1);

namespace Storm\Projector\Tests\Fixture;

use Doctrine\DBAL\Connection;
use Storm\Chronicler\Record\EventRecord;
use Storm\Projector\Definition\AbstractGroupedReadModel;

/**
 * GroupedReadModel stub for routing unit tests: returns the preset keys for every event and
 * records each applyTo() call, with an optional per-key result defaulting to true when missing.
 */
final class RecordingGroupedReadModel extends AbstractGroupedReadModel
{
    /** @var list<string> */
    public array $appliedKeys = [];

    /**
     * @param  list<string>  $keys  what groupKeys() returns for every event
     * @param  array<string, bool>  $results  applyTo() result per key
     */
    public function __construct(
        private readonly array $keys,
        private readonly array $results = [],
    ) {}

    public function name(): string
    {
        return 'rm_grouped_stub';
    }

    public function categories(): array
    {
        return [];
    }

    public function eventTypes(): array
    {
        return [];
    }

    public function initialize(Connection $tx): void {}

    public function clear(Connection $tx): void {}

    public function drop(Connection $tx): void {}

    public function groupKeys(EventRecord $event): array
    {
        return $this->keys;
    }

    public function applyTo(string $key, EventRecord $event, Connection $tx): bool
    {
        $this->appliedKeys[] = $key;

        return $this->results[$key] ?? true;
    }
}
