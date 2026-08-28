<?php

declare(strict_types=1);

namespace Storm\Projector\Management;

use Doctrine\DBAL\Exception;
use JsonException;
use RuntimeException;
use Storm\Projector\Definition\FanOutLinkProjection;
use Storm\Projector\Definition\LinkProjection;
use Storm\Projector\Definition\MigratedReadModel;
use Storm\Projector\Definition\PersistentProjection;
use Storm\Projector\Definition\QueryProjection;
use Storm\Projector\Exception\CannotRetire;
use Storm\Projector\Exception\DuplicateProjection;
use Storm\Projector\Exception\ProjectionBusy;
use Storm\Projector\Exception\ProjectionHomeMismatch;
use Storm\Projector\Exception\ProjectionNotFailed;
use Storm\Projector\Exception\ProjectionNotOrphaned;
use Storm\Projector\Exception\UnknownProjection;
use Storm\Projector\Exception\UnsupportedProjection;
use Storm\Projector\Link\EventLinkWriter;
use Storm\Projector\Registry\ProjectionRegistry;
use Storm\Projector\Run\ProjectionLane;
use Storm\Projector\Run\ProjectionLanes;
use Storm\Projector\Store\ProjectionStatus;
use Throwable;

use function array_filter;
use function array_values;

/**
 * Synchronous reset/delete of a projection, with no async `pending_*` handshake. Both require the
 * projection to not be running, holding no live lease, the truth since status can be stale, and run in
 * one transaction: the lease guard first, `lockAndAssertNotRunning`, which locks the row `FOR UPDATE` so
 * a concurrent `claimLease` cannot slip in between the check and the mutation, then clear the output and
 * update or remove the state row, atomically.
 *
 * - Reset clears the output, a read-model truncate or link rows deleted, zeroes the checkpoint, sets
 *   status to `idle`, and realigns the stored generation to the rebuilt definition's, for a replay from
 *   scratch.
 *
 * - Delete drops the output, a read-model table dropped or link rows deleted, and removes the state row.
 *   A MigratedReadModel is the exception: a migration owns its SCHEMA, so delete leaves the table standing
 *   but still empties it; storm drops only what it mounts, and owns the content either way.
 *
 * - Retire removes a stream producer's state row but keeps its derived stream for consumers, detaching
 *   the producer versus delete dropping the product; a stream producer only, `LinkProjection` or
 *   `FanOutLinkProjection`, opt-in.
 *
 * Output clearing/dropping is delegated to the projection, `PersistentProjection::clear()` and
 * `PersistentProjection::drop()`, so management knows no concrete type. Both resolve the projection from
 * the registry, so the code must still exist. The exception is `forget`, for an orphaned row whose class
 * was removed: with no instance to delegate to, it deletes the link rows directly via `EventLinkWriter`,
 * the one place that dependency is still needed.
 */
final readonly class ProjectionManagement
{
    public function __construct(
        private ProjectionRegistry $registry,
        private ProjectionLanes $lanes,
        private EventLinkWriter $links,
    ) {}

    /**
     * Reset makes the projection PRISTINE from ANY state, including never-run: the output is initialized
     * before it is cleared, the same contract the run path honors at start, so resetting a projection
     * whose table never existed succeeds instead of dying on the TRUNCATE. This matters because an app
     * delete-process may reset every dependent, run or not. On a MigratedReadModel the initializing is an
     * ASSERT, so a never-migrated table fails loud with its own message instead of a raw undefined-table
     * error.
     *
     * @throws UnknownProjection when no projection is registered under `$name`
     * @throws UnsupportedProjection when the projection is not persistent, with no output to clear
     * @throws ProjectionHomeMismatch when `$name`'s state sits on a home its current kind does not own
     * @throws ProjectionBusy when a worker holds a live lease on the projection
     * @throws RuntimeException on a failure initializing or clearing the projection's output
     * @throws Exception on a DBAL failure of the reset transaction
     * @throws Throwable when transactional code throws
     */
    public function reset(string $name): bool
    {
        $projection = $this->getPersistent($name);
        $lane = $this->lanes->laneFor($projection);
        $this->lanes->assertHomedOn($name, $lane); // never clear an empty row while the real state sits elsewhere

        return (bool) $lane->connection->transactional(function () use ($lane, $name, $projection): bool {
            $lane->store->lockAndAssertNotRunning($name); // in-tx guard: serializes a concurrent claim
            $this->clearSupersededOutput($lane, $name, $projection); // what the CHECKPOINT produced, per the row
            $projection->initialize($lane->connection); // pristine from any state; a never-run output exists before it clears
            $projection->clear($lane->connection);

            // whether a STATE row was realigned: pristine is delivered either way, but an operator
            // resetting a name that never ran deserves to hear that only the output was cleared,
            // not to read success into a row that was never there
            return $lane->store->reset($name, $projection->generation());
        });
    }

    /**
     * @throws UnknownProjection when no projection is registered under `$name`
     * @throws UnsupportedProjection when the projection is not persistent, with no output to drop
     * @throws ProjectionHomeMismatch when `$name`'s state sits on a home its current kind does not own
     * @throws ProjectionBusy when a worker holds a live lease on the projection
     * @throws RuntimeException on a failure dropping the projection's output
     * @throws Exception on a DBAL failure of the delete transaction
     * @throws Throwable when transactional code throws
     */
    public function delete(string $name): void
    {
        $projection = $this->getPersistent($name);
        $lane = $this->lanes->laneFor($projection);
        $this->lanes->assertHomedOn($name, $lane);

        $lane->connection->transactional(function () use ($lane, $name, $projection): void {
            $lane->store->lockAndAssertNotRunning($name); // in-tx guard: serializes a concurrent claim

            $this->clearSupersededOutput($lane, $name, $projection); // the row is about to go; nothing else could reach it

            // Storm drops only the tables it MOUNTS. For a migration-owned one it still owns the
            // CONTENT, which is event-derived and replayable; the exact line `MigratedReadModelBehavior`
            // already draws with `drop()` refusing and `clear()` truncating. Leaving the rows behind would
            // make the verb lie: with the class still registered, the next run restarts at checkpoint 0
            // and refolds the whole history over them, which an additive fold double-counts in silence.
            $projection instanceof MigratedReadModel
                ? $projection->clear($lane->connection)
                : $projection->drop($lane->connection);

            $lane->store->delete($name);
        });
    }

    /**
     * Retire a stream producer: remove its bookkeeping, the state row, and checkpoint, but keep its
     * derived stream, `event_links`, for consumers. The decommission-but-keep counterpart of delete,
     * which drops the stream; detaching the producer versus dropping the product, a distinct business
     * intent kept apart, not a `keepOutput` flag. Only a stream producer can be retired, and there are TWO
     * kinds: a `LinkProjection` with one fixed target, and a `FanOutLinkProjection` with a prefix, siblings
     * rather than one a subtype of the other. Orphaning a stream, leaving it producer-less, is deliberate,
     * so it must be acknowledged
     * via `$orphanStream`. Wrapped in a transaction only to hold the lifecycle lock across the row
     * removal, with no output op of its own.
     *
     * @throws UnknownProjection when no projection is registered under `$name`
     * @throws CannotRetire when the projection is not a stream producer, or the orphaning is unacknowledged
     * @throws ProjectionHomeMismatch when `$name`'s state sits on a home its current kind does not own
     * @throws ProjectionBusy when a worker holds a live lease on the projection
     * @throws Exception on a DBAL failure removing the state row
     * @throws Throwable when transactional code throws
     */
    public function retire(string $name, bool $orphanStream): bool
    {
        $projection = $this->registry->get($name);

        // A fan-out is a producer too, and a SIBLING of LinkProjection rather than a subtype: it has no
        // single targetStream(), it has a prefix. Everything else in the module treats it as one, the
        // freshness waiter resolving it by prefix and forget cleaning by prefix; only this guard read
        // it as a read model, and sent the operator to the verb that DELETES the very links they asked
        // to keep, bumping every consumer's source revision with them.
        if (! $projection instanceof LinkProjection && ! $projection instanceof FanOutLinkProjection) {
            throw CannotRetire::notAStreamProducer($name);
        }

        if (! $orphanStream) {
            throw CannotRetire::streamNotAcknowledged($name);
        }

        $lane = $this->lanes->laneFor($projection); // a link producer: the events lane, by the kind rule
        $this->lanes->assertHomedOn($name, $lane);

        // The ROW is this verb's only deliverable, unlike reset and delete which also touch output, so
        // a name registered but never run has nothing to retire and must not read as decommissioned.
        return (bool) $lane->connection->transactional(function () use ($lane, $name): bool {
            $lane->store->lockAndAssertNotRunning($name); // in-tx guard: serializes a concurrent claim

            return $lane->store->delete($name); // removes the row; event_links is left intact for consumers
        });
    }

    /**
     * Forget an orphaned projection, a `projections` row whose class was removed, so it is no longer in
     * the registry and delete cannot resolve it. Drops the tracking row and its link rows in one
     * transaction; the producer's output is read from the row, `target_stream` for a link projection or
     * `target_prefix` for a fan-out, so no instance is needed. The read-model table, if any, is left to
     * the operator; its name and shape lived only in the removed code.
     *
     * Keyed on absence of code, a registry miss, so it is deliberately limited to the always-rebuildable
     * outputs, the row and `event_links`, both reconstructable by replay, never anything irreversible.
     *
     * @throws ProjectionNotOrphaned when the code is still registered; use delete instead
     * @throws UnknownProjection when no row exists under `$name`, so there is nothing to forget
     * @throws DuplicateProjection when the orphan has a row on both homes, an ambiguous split state
     * @throws ProjectionBusy when a worker holds a live lease on the projection
     * @throws JsonException when the projection's stored row is malformed
     * @throws Exception on a DBAL failure of the forget transaction
     * @throws Throwable when transactional code throws
     */
    public function forget(string $name): void
    {
        if ($this->registry->has($name)) {
            throw ProjectionNotOrphaned::stillRegistered($name);
        }

        // with the class gone, the row's location is the only home signal left: locate it
        $matches = [];
        foreach ($this->lanes->distinct() as $lane) {
            $row = $lane->store->findRow($name);
            if ($row !== null) {
                $matches[] = [$lane, $row];
            }
        }

        if ($matches === []) {
            throw UnknownProjection::named($name); // no row on any home, so nothing to forget
        }

        if (count($matches) > 1) {
            // Picking the first would report success while leaving the other checkpoint, lease and
            // output behind. The same split is refused by HomedProjectionStore::all().
            throw DuplicateProjection::splitAcrossHomes($name);
        }

        [$located, $row] = $matches[0];

        $located->connection->transactional(function () use ($located, $name, $row): void {
            $located->store->lockAndAssertNotRunning($name); // in-tx guard: serializes a concurrent claim

            if ($row->targetStream !== null) {
                $this->links->deleteLinks($located->connection, $row->targetStream); // a link projection's rows
            }

            if ($row->targetPrefix !== null) {
                $this->links->deleteLinksByPrefix($located->connection, $row->targetPrefix); // a fan-out's rows
            }

            $located->store->delete($name);
        });
    }

    /**
     * Recover a failed projection without replaying: clear the failure context and set status to `idle`,
     * keeping the checkpoint, since the failed batch rolled back so the position is consistent. The next
     * run catches up from there. Contrast reset, which zeroes the checkpoint for a full rebuild.
     *
     * The pre-check reads and refuses with the current truth, for the operator's message; the write
     * itself is compare-and-set on the states `canRetry()` accepts, so a transition landing between the
     * read and the write, say a concurrent `reset` then `pause`, is never overwritten to `idle`. When
     * the compare-and-set misses, the row is re-read under the held lock and the refusal names the status
     * the write actually found, not the one the pre-check saw.
     *
     * @throws UnknownProjection when no projection is registered under `$name`
     * @throws ProjectionHomeMismatch when `$name`'s state sits on a home its current kind does not own
     * @throws ProjectionNotFailed when the projection is not in the `failed` status, at the pre-check or
     *                             when it left `failed` before the compare-and-set landed
     * @throws ProjectionBusy when a worker holds a live lease on the projection
     * @throws JsonException when the projection's stored row is malformed
     * @throws Exception on a DBAL failure
     * @throws Throwable when transactional code throws
     */
    public function retry(string $name): void
    {
        $projection = $this->registry->get($name); // a known projection, UnknownProjection otherwise: its code must exist
        $lane = $this->lanes->laneFor($projection);
        $this->lanes->assertHomedOn($name, $lane);

        $row = $lane->store->findRow($name);
        if ($row === null || ! $row->status->canRetry()) {
            throw ProjectionNotFailed::cannotRetry($name, $row?->status);
        }

        $lane->connection->transactional(function () use ($lane, $name): void {
            $lane->store->lockAndAssertNotRunning($name); // in-tx guard: serializes a concurrent claim

            // the same predicate the pre-check validated with, carried INTO the statement: derived, so
            // the validation and the compare-and-set cannot drift apart
            $expected = array_values(array_filter(
                ProjectionStatus::cases(),
                static fn (ProjectionStatus $status): bool => $status->canRetry(),
            ));

            if (! $lane->store->retry($name, ...$expected)) {
                // the row left `failed` between the pre-check and this write; it is locked now, so this
                // re-read is the truth the compare-and-set refused on: report it, never overwrite it
                throw ProjectionNotFailed::cannotRetry($name, $lane->store->findRow($name)?->status);
            }
        });
    }

    /**
     * Clear the output this checkpoint ACTUALLY produced, when the redeployed code no longer declares it.
     *
     * `clear()`/`drop()` run on the current instance, so they only ever reach the namespace the NEW code
     * names. A producer whose target was renamed is refused by the topology gate, which sends the dev to
     * bump and reset; that reset would then empty the new, still-empty namespace while the stream the
     * checkpoint really built survives with no producer and no way back: `forget` needs the class gone,
     * `retire` needs a row. So the stored row, already locked by the caller, is the authority on what to
     * clean; the declaration is only the authority on what comes next.
     *
     * Deleting rather than keeping is the default on purpose: an orphaned derived stream is invisible
     * garbage, and preserving one is a real but SEPARATE intent, spelled `retire --orphan-stream`. The
     * delete goes through `EventLinkWriter`, so it bumps the old stream's revision too, and any consumer
     * still folding it is refused instead of silently reading a stream that stopped growing.
     *
     * @throws Exception on a DBAL failure
     * @throws JsonException when the projection's stored row is malformed
     */
    private function clearSupersededOutput(ProjectionLane $lane, string $name, PersistentProjection $projection): void
    {
        $row = $lane->store->findRow($name);
        if ($row === null) {
            return; // never run: no stored output to supersede
        }

        $declaredStream = $projection instanceof LinkProjection ? $projection->targetStream()->toString() : null;
        $declaredPrefix = $projection instanceof FanOutLinkProjection ? $projection->targetPrefix() : null;

        if ($row->targetStream !== null && $row->targetStream->toString() !== $declaredStream) {
            $this->links->deleteLinks($lane->connection, $row->targetStream);
        }

        if ($row->targetPrefix !== null && $row->targetPrefix !== $declaredPrefix) {
            $this->links->deleteLinksByPrefix($lane->connection, $row->targetPrefix);
        }
    }

    /**
     * Resolve a persistent projection: `reset`/`delete` operate on its output, which only a
     * PersistentProjection has; a QueryProjection folds in memory.
     *
     * @throws UnknownProjection when no projection is registered under `$name`
     * @throws UnsupportedProjection when the registered projection is not persistent
     *
     * @see QueryProjection
     */
    private function getPersistent(string $name): PersistentProjection
    {
        $projection = $this->registry->get($name);

        if (! $projection instanceof PersistentProjection) {
            throw UnsupportedProjection::notPersistent($name);
        }

        return $projection;
    }
}
