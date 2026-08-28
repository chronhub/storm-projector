<?php

declare(strict_types=1);

namespace Storm\Projector\Definition;

use Doctrine\DBAL\Connection;
use Storm\Chronicler\Record\EventRecord;
use Storm\Projector\Exception\InvalidGroupKey;

/**
 * The generic `GroupedReadModel` machinery: `apply()` extracts the keys, collapses duplicates, and
 * folds the event once per key via `applyTo()`. A concrete grouped read model supplies `name()`,
 * `categories()`, `eventTypes()`, `groupKeys()`, `applyTo()` and its own table lifecycle over
 * `initialize`/`clear`/`drop`; the routing is identical across grouped views, so it lives here once.
 *
 * Unlike `AbstractFanOutLinkProjection` this base owns no output plumbing: links are uniform, living in
 * `event_links` and cleared by prefix, whereas read-model tables are not, each concrete knowing its own
 * shape, so the `PersistentProjection` lifecycle stays on the concrete.
 *
 * @see AbstractFanOutLinkProjection
 */
abstract class AbstractGroupedReadModel implements GroupedReadModel
{
    /**
     * {@inheritDoc}
     *
     * @throws InvalidGroupKey on an empty-string key, a malformed extraction, not a skip
     */
    public function apply(EventRecord $event, Connection $tx): bool
    {
        $applied = false;

        foreach (array_unique($this->groupKeys($event)) as $key) {
            if ($key === '') {
                throw InvalidGroupKey::empty($this->name());
            }

            $applied = $this->applyTo($key, $event, $tx) || $applied;
        }

        return $applied;
    }

    public function generation(): int
    {
        return 1; // override to bump when a change makes the built rows incompatible
    }
}
