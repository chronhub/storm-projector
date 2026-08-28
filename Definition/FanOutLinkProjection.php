<?php

declare(strict_types=1);

namespace Storm\Projector\Definition;

use Storm\Chronicler\Record\EventRecord;
use Storm\Projector\Link\EventLinkWriter;
use Storm\Stream\StreamName;

/**
 * A `LinkProjection` variant that fans one source scan out into many computed targets, one derived
 * stream per key, be it event type, correlation or any per-event dimension, instead of a
 * `LinkProjection`'s single fixed `targetStream()`. An app primitive: the framework ships no concrete
 * fan-out, because category and type are materialized `event_store` columns served natively by the
 * selectors, and the prefix is a plain app naming convention, e.g. `et-` yielding `et-<type>` streams.
 *
 * Filtered like any persistent projection, so prefer a scoped `categories()`/`eventTypes()` over a
 * blanket all-categories scan to avoid write amplification. Checkpointed on `sequence_no`: one scan, N
 * targets, each densely positioned by `EventLinkWriter` at `max+1` per target. `targetFor()` computes
 * the per-event target, returning null to skip, e.g. an event with no correlation; `targetPrefix()` is
 * the shared prefix its clear/drop delete by, via `EventLinkWriter::deleteLinksByPrefix`.
 *
 * Distinct from a `LinkProjection`, which has one fixed target: a fan-out has no single `targetStream()`,
 * so it is a sibling, not a subtype. `AbstractFanOutLinkProjection` carries the generic apply/clear/drop.
 *
 * @see LinkProjection
 * @see AbstractFanOutLinkProjection
 * @see EventLinkWriter::deleteLinksByPrefix()
 */
interface FanOutLinkProjection extends FilteredProjection
{
    /**
     * The derived stream this event links into, or null to skip it, no link; the checkpoint still
     * advances, so it is not re-scanned.
     */
    public function targetFor(EventRecord $event): ?StreamName;

    /**
     * The shared prefix of every target this projection produces, e.g. `et-`, an app naming convention.
     * Clear/drop delete every link under it, and the runner records it on the `projections` row so
     * forget can do the same once the class is removed.
     */
    public function targetPrefix(): string;
}
