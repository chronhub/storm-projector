<?php

declare(strict_types=1);

namespace Storm\Projector\Definition;

use Doctrine\DBAL\Connection;
use Storm\Chronicler\Record\EventRecord;
use Storm\Projector\Exception\InvalidDerivedNamespace;
use Storm\Projector\Link\EventLinkWriter;

use function str_starts_with;

/**
 * The generic `FanOutLinkProjection` machinery: `apply()` links each event into its `targetFor()`,
 * skipping nulls, and `clear()`/`drop()` delete every link under `targetPrefix()`. A concrete fan-out
 * supplies only `name()`, `categories()`, `eventTypes()`, `targetFor()` and `targetPrefix()`; the link
 * plumbing is identical across fan-outs, so it lives here once.
 */
abstract class AbstractFanOutLinkProjection implements FanOutLinkProjection
{
    public function __construct(
        protected readonly EventLinkWriter $linkWriter,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws InvalidDerivedNamespace when `targetFor()` computes a target outside `targetPrefix()`;
     *                                 the prefix-scoped clear/drop would miss it and leak the output
     */
    public function apply(EventRecord $event, Connection $tx): bool
    {
        $target = $this->targetFor($event);

        if ($target === null) {
            return false;
        }

        // targetFor() is dynamic, so its result cannot be checked at startup like the prefix itself:
        // a target outside the declared prefix would be written here but MISSED by the prefix-scoped
        // reset/delete, leaking output. Refused at the write seam.
        if (! str_starts_with($target->toString(), $this->targetPrefix())) {
            throw InvalidDerivedNamespace::targetOutsidePrefix(static::class, $target->toString(), $this->targetPrefix());
        }

        return $this->linkWriter->link($tx, $event->position->toOrdinal(), $target);
    }

    public function initialize(Connection $tx): void {} // event_links is framework-owned, created by migration

    public function clear(Connection $tx): void
    {
        $this->linkWriter->deleteLinksByPrefix($tx, $this->targetPrefix());
    }

    public function drop(Connection $tx): void
    {
        $this->linkWriter->deleteLinksByPrefix($tx, $this->targetPrefix());
    }

    public function generation(): int
    {
        return 1; // override to bump when a change makes existing links incompatible
    }
}
