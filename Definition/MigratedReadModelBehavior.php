<?php

declare(strict_types=1);

namespace Storm\Projector\Definition;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use LogicException;
use Storm\Projector\Exception\CannotDropMigratedReadModel;
use Storm\Projector\Exception\MigratedTableMissing;

/**
 * Default lifecycle for a `MigratedReadModel`: the table's schema is owned by a migration upstream, so
 * storm hands off the DDL and only manages the content.
 *
 * - `initialize()` asserts the table exists, failing fast if the migration has not run, and never
 *   creates it.
 *
 * - `clear()` truncates the content, which is event-derived, so `reset`/replay rebuilds it while the
 *   migration-owned schema is untouched.
 *
 * - `drop()` throws, a safety net, since `delete` already skips it via the marker.
 *
 * - `hasContent()` probes for leftover rows, so the runner's stale-content gate can refuse a
 *   checkpoint-zero run over content a forgotten checkpoint left behind, instead of double-counting.
 *
 * The host supplies `tableName()` only, plus its fold and selection: every method is HANDED its home
 * connection, so a host holding one of its own would be holding the wrong one under the store split. A
 * host wanting different teardown overrides the method; the marker, not this trait, is what drives the
 * framework's behavior.
 *
 * @see MigratedReadModel
 */
trait MigratedReadModelBehavior
{
    /**
     * The migration-owned table whose content this lifecycle manages.
     */
    abstract protected function tableName(): string;

    /**
     * @throws LogicException when `tableName()` declares a schema-qualified name, refused HERE
     *                        because the probes disagree on it: `to_regclass` parses the qualifier
     *                        while the quoted TRUNCATE and content probe wrap the whole string in
     *                        ONE identifier, so a qualified name would pass this gate and then
     *                        fail at `clear()` with a confusing missing-relation error; declare
     *                        the bare name and let the connection's `search_path` own the schema
     * @throws MigratedTableMissing when the migration-owned table is absent, the migration not having run
     * @throws Exception on a DBAL failure of the existence probe
     */
    public function initialize(Connection $tx): void
    {
        if (str_contains($this->tableName(), '.')) {
            throw new LogicException(sprintf(
                'MigratedReadModel table name "%s" is schema-qualified — declare the bare table name and let the connection\'s search_path own the schema; the quoted TRUNCATE and content probes treat the whole string as one identifier and would fail on it far from this declaration.',
                $this->tableName(),
            ));
        }

        if (! $this->tableExists($tx)) {
            throw MigratedTableMissing::forTable($this->tableName());
        }
    }

    /**
     * Whether the migration-owned table is present. The default probes the catalog; it is a seam, so the
     * assertion is unit-testable without the database, and a host could swap a cheaper or cached probe.
     *
     * @throws Exception on a DBAL failure of the probe
     */
    protected function tableExists(Connection $tx): bool
    {
        return $tx->fetchOne(
            /** @lang PostgreSQL */
            'SELECT to_regclass(:table)',
            ['table' => $this->tableName()],
        ) !== null;
    }

    /**
     * Whether the migration-owned table already holds rows. An absent table reads as EMPTY on purpose:
     * `initialize()` owns the missing-table refusal and its message, so the stale-content gate calling
     * this must not preempt it with a raw undefined-table error.
     *
     * @throws Exception on a DBAL failure of the probe
     */
    public function hasContent(Connection $tx): bool
    {
        if (! $this->tableExists($tx)) {
            return false;
        }

        return (bool) $tx->fetchOne(
            /** @lang PostgreSQL */
            'SELECT EXISTS (SELECT 1 FROM '.$tx->getDatabasePlatform()->quoteSingleIdentifier($this->tableName()).')',
        );
    }

    /**
     * @throws Exception on a DBAL failure of the truncate
     */
    public function clear(Connection $tx): void
    {
        $tx->executeStatement(
            /** @lang PostgreSQL */
            'TRUNCATE TABLE '.$tx->getDatabasePlatform()->quoteSingleIdentifier($this->tableName()),
        );
    }

    /**
     * @throws CannotDropMigratedReadModel always, since the table's schema is owned by a migration
     */
    public function drop(Connection $tx): void
    {
        throw CannotDropMigratedReadModel::forTable($this->tableName());
    }

    public function generation(): int
    {
        return 1; // override to bump when a change makes the projected data incompatible
    }
}
