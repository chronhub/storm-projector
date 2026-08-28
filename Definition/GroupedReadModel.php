<?php

declare(strict_types=1);

namespace Storm\Projector\Definition;

use Doctrine\DBAL\Connection;
use Storm\Chronicler\Record\EventRecord;
use Throwable;

/**
 * A `ReadModel` variant that routes one source scan into one row per grouping key, the write-through
 * answer to Marten's `MultiStreamProjection`. For views whose key is not the stream's aggregate id: a
 * cross-category view keyed by a payload or header dimension, such as a customer-360 row fed by events
 * from several aggregates that each carry the customer's identity. When the key IS the shared aggregate
 * id, a plain `ReadModel` already covers it, folding N categories and keying by `aggregateId()`, with no
 * grouping needed.
 *
 * The key must come from the event. `groupKeys()` derives its keys from the event itself, its payload or
 * header, or from state this projection wrote, never from another projection's table: that projection's
 * checkpoint diverges from yours, so the lookup result depends on daemon timing and the fold stops being
 * replay-deterministic. Storm's sequential apply in global `sequence_no` order, inside the batch
 * transaction, already makes a lookup against this projection's own earlier writes consistent; the
 * cross-projection lookup is the one hazard left, so it is forbidden rather than machinery'd. This is
 * what replaces Marten's batch-aware `IAggregateGrouper`.
 *
 * One event contributes at most once per key: duplicate keys collapse before `applyTo()` runs, so a
 * self-transfer touching the same view twice must not double-fold. An empty key list skips the event; the
 * checkpoint still advances, so it is not re-scanned. The grouping key is fold policy, not selection: it
 * is invisible to the topology gate over `event_classes`/`source_stream`, so a change in key semantics is
 * a `generation()` bump, the dev's call, like any fold change.
 *
 * Distinct from `FanOutLinkProjection`, which has the same routing shape but writes derived streams into
 * `event_links`: this is the sibling for the read-model lane, and unlike links the output table is the
 * concrete's own, so it keeps `initialize()`/`clear()`/`drop()`.
 *
 * @see AbstractGroupedReadModel
 * @see FanOutLinkProjection
 * @see PersistentProjection::generation()
 */
interface GroupedReadModel extends FilteredProjection, ReadModel
{
    /**
     * The grouping keys this event contributes to; `[]` to skip it, touching no row while the checkpoint
     * still advances. Keys must be non-empty strings; duplicates collapse to one `applyTo()` call.
     *
     * @return list<string>
     */
    public function groupKeys(EventRecord $event): array;

    /**
     * Fold this event into the row identified by `$key`, writing through the batch connection. Returns
     * false for a no-op, e.g. an upsert that changed nothing worth counting.
     *
     * @throws Throwable any handler failure, such as a DBAL write; propagated to the runner, which
     *                   rolls back the batch transaction and marks the projection failed
     */
    public function applyTo(string $key, EventRecord $event, Connection $tx): bool;
}
