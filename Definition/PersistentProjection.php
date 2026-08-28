<?php

declare(strict_types=1);

namespace Storm\Projector\Definition;

use Doctrine\DBAL\Connection;
use RuntimeException;
use Storm\Projector\Link\EventLinkWriter;
use Storm\Projector\Management\ProjectionManagement;
use Storm\Projector\Query\QueryProjectionRunner;
use Storm\Projector\Run\ProjectionRunner;
use Throwable;

/**
 * The checkpointed projection family: projections that persist their derived state, so they checkpoint
 * and resume and run on the `ProjectionRunner`. `ReadModel` holds a table, `LinkProjection` holds
 * `event_links`. The runner accepts any `PersistentProjection`, a positive, extensible guard: a new
 * persistent type just extends this. A `QueryProjection` is not persistent, folding in memory, so it
 * runs on the checkpoint-less `QueryProjectionRunner` instead.
 *
 * Because the state is durable, a persistent projection owns its output lifecycle, the three methods
 * below, the one thing every persistent projection shares and a `QueryProjection` has none of. Kept on
 * this contract rather than a separate interface on purpose: its population is identical to the
 * persistent family, so splitting it out would only buy two interfaces and two guards for one set.
 * `ProjectionRunner` calls `initialize()` once before the loop; `ProjectionManagement` calls `clear()`
 * on reset and `drop()` on delete, each inside its own transaction.
 *
 * All four methods, `apply()` included, are HANDED the connection; a persistent projection must not hold
 * one of its own. Under the read-model store split a projection's output and its checkpoint must commit
 * on the same database, so connection identity is a correctness property, not a wiring preference; an
 * application projection that simply asks the container for `Doctrine\DBAL\Connection` receives the
 * DEFAULT one, which the container cannot rewrite per projection. A lifecycle holding its own connection
 * would let `apply()` land on the lane while `clear()` lands wherever the constructor pointed, so with
 * the table present on both databases `reset` truncates one copy, rewinds the checkpoint over the other,
 * and the replay double-applies. Passing the lane connection makes that unrepresentable rather than
 * merely discouraged.
 *
 * What each impl does with it:
 * - `ReadModel`: `initialize` creates the table, `clear` truncates it, `drop` drops it.
 * - `LinkProjection`: `initialize` is a no-op, since `event_links` is framework-owned; `clear` and
 *   `drop` delete its links via `EventLinkWriter`.
 *
 * The selection, what events it reads, is a separate, mutually exclusive concern: a persistent
 * projection implements exactly one of `FilteredProjection` for categories/types or
 * `DerivedStreamProjection` for a derived stream. Kept off this contract on purpose, so a derived-stream
 * consumer simply has no categories/types to mis-declare. The selection is fixed because the checkpoint
 * is tied to it: it cannot change what it reads without invalidating its position.
 *
 * @see ProjectionRunner
 * @see ReadModel
 * @see LinkProjection
 * @see QueryProjection
 * @see QueryProjectionRunner
 * @see ProjectionManagement
 * @see EventLinkWriter
 * @see FilteredProjection
 * @see DerivedStreamProjection
 */
interface PersistentProjection extends Projection
{
    /**
     * Ensure the output exists, idempotent; called once before the run.
     *
     * @param  Connection  $tx  the projection's HOME connection, the same one `apply()` receives
     *
     * @throws RuntimeException on a failure creating the output, e.g. the read-model schema
     */
    public function initialize(Connection $tx): void;

    /**
     * Empty the output, keeping its structure, for `storm:projection:reset`, so a replay from position 0
     * rebuilds cleanly: a read-model truncate, or link rows deleted.
     *
     * Keep a read model FK-free: a table referenced by an incoming foreign key cannot be `TRUNCATE`d,
     * which Postgres refuses on constraint grounds even when the referencing rows are gone, and there is
     * no disable-FK escape on PG unlike MySQL's `FOREIGN_KEY_CHECKS=0`. A multi-table read model should
     * own every table and clear them together, or `CASCADE`. Same issue for `drop()`.
     *
     * @param  Connection  $tx  the projection's HOME connection, inside management's lifecycle transaction
     *
     * @throws Throwable on a failure clearing the output
     */
    public function clear(Connection $tx): void;

    /**
     * Remove the output entirely, for `storm:projection:delete`: a read-model table dropped, or link rows
     * deleted. Like `clear()`, a table referenced by an incoming foreign key cannot be `DROP`ped on
     * Postgres, since the dependency is refused and `CASCADE` would drop the referencing table's
     * constraint without rebuilding it. Keep read models FK-free, or own and drop the whole set.
     *
     * @param  Connection  $tx  the projection's HOME connection, inside management's lifecycle transaction
     *
     * @throws Throwable on a failure dropping the output
     */
    public function drop(Connection $tx): void;

    /**
     * The build generation of this projection's definition. Bump it when a change makes the read model
     * incompatible with what the old code built, a changed fold or shape: the run then refuses to advance
     * over a read model built by an older generation until it is rebuilt via `reset` plus replay, or forced
     * with `--allow-stale`. A semantics-preserving refactor does not bump.
     *
     * Storm only traces it, comparing this declared generation to the stored one the read model was built
     * under, and gates on a mismatch; when to bump is the dev's call, the one thing Storm cannot know.
     *
     * Return a positive int; 0 is reserved as the unstamped sentinel, a fresh or pre-feature row carrying
     * 0 and adopting the declared generation on its first run.
     */
    public function generation(): int;
}
