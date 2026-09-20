<?php

declare(strict_types=1);

namespace Storm\Projector\Definition;

use Doctrine\DBAL\Connection;
use Storm\Chronicler\Record\EventRecord;
use Storm\Projector\Exception\InvalidDerivedNamespace;
use Storm\Projector\Link\EventLinkWriter;
use Storm\Projector\Link\PendingLink;
use Storm\Stream\StreamName;

use function str_starts_with;

/**
 * The generic `FanOutLinkProjection` machinery: `apply()` links each event into its `targetFor()`,
 * skipping nulls, and `clear()`/`drop()` delete every link under `targetPrefix()`. A concrete fan-out
 * supplies only `name()`, `categories()`, `eventTypes()`, `targetFor()` and `targetPrefix()`; the link
 * plumbing is identical across fan-outs, so it lives here once.
 */
abstract class AbstractFanOutLinkProjection implements BatchProjection, FanOutLinkProjection
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
        $target = $this->targetOf($event);

        return $target !== null && $this->linkWriter->link($tx, $event->position->toOrdinal(), $target);
    }

    /**
     * The batch verb: the targets of the whole batch decided first, under the same guards as
     * `apply()`, so a refused target refuses the batch before any link is written, then the links
     * handed to the writer in one call.
     */
    public function applyBatch(array $events, Connection $tx): int
    {
        $links = [];
        foreach ($events as $event) {
            $target = $this->targetOf($event);
            if ($target !== null) {
                $links[] = new PendingLink($event->position->toOrdinal(), $target);
            }
        }

        return $links === [] ? 0 : $this->linkWriter->linkMany($tx, $links);
    }

    /**
     * The target of one event, null when the event joins no stream, refused when it lies outside
     * the declared prefix: targetFor() is dynamic, so its result cannot be checked at startup like
     * the prefix itself, and a target outside the prefix would be written but MISSED by the
     * prefix-scoped reset/delete, leaking output. Refused at the write seam, for both verbs.
     *
     * @throws InvalidDerivedNamespace
     */
    private function targetOf(EventRecord $event): ?StreamName
    {
        $target = $this->targetFor($event);

        if ($target !== null && ! str_starts_with($target->toString(), $this->targetPrefix())) {
            throw InvalidDerivedNamespace::targetOutsidePrefix(static::class, $target->toString(), $this->targetPrefix());
        }

        return $target;
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
