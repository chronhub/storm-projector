<?php

declare(strict_types=1);

namespace Storm\Projector\Definition;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;

/**
 * A `ReadModel` whose table is owned by a normal migration upstream, such as a Doctrine migration, not
 * self-mounted by the projection. It is the SAME read model in every other way, same fold, and it still
 * declares its selection as a `FilteredProjection` or `DerivedStreamProjection`; only the mounting
 * differs: storm neither creates nor drops the table, the migration does, which is what unlocks a
 * declared schema and ALTER-in-place without Storm reinventing migrations.
 *
 * This is a provenance marker, a third axis orthogonal to output-kind, ReadModel or Link, and selection,
 * Filtered or DerivedStream. The framework reads it in exactly two places, both drawing the same line,
 * the migration owning the schema and storm owning the content, which is event-derived and replayable:
 *
 * - `ProjectionManagement::delete()` keeps the migration-owned table standing but still EMPTIES it, via
 *   `clear()` instead of `drop()`, before removing the checkpoint row; leaving the rows behind would let
 *   the still-registered class refold the whole history over them from checkpoint 0.
 *
 * - `ProjectionRunner::run()` refuses a checkpoint-zero run, no row or a reset one, when the table is
 *   already non-empty, via `hasContent()`: that content can only be stale, left behind by `forget`, and
 *   refolding over it double-counts in silence; the same defect reached through the other door.
 *
 * Pair it with `MigratedReadModelBehavior` for the default lifecycle: assert-on-init, truncate-on-clear,
 * throw-on-drop, catalog-probed `hasContent`.
 *
 * @see MigratedReadModelBehavior
 */
interface MigratedReadModel extends ReadModel
{
    /**
     * Whether the migration-owned table already holds rows.
     *
     * Read by the runner's stale-content gate at checkpoint zero: storm has folded nothing yet, so any
     * content is stale, left behind by `forget`, which keeps the table by doctrine, and refolding the
     * history over it would double-count. Answering false for an ABSENT table is part of the contract:
     * `initialize()` owns the missing-table refusal and its message, and the gate must not preempt it
     * with a raw undefined-table error.
     *
     * @throws Exception on a database failure of the probe
     */
    public function hasContent(Connection $tx): bool;
}
