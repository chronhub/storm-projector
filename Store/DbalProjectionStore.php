<?php

declare(strict_types=1);

namespace Storm\Projector\Store;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use JsonException;
use Storm\Projector\Exception\InvalidStatusTransition;
use Storm\Projector\Exception\LeaseLost;
use Storm\Projector\Exception\ProjectionBusy;
use Storm\Projector\Schema\ProjectionSchema;
use Storm\Stream\StreamName;
use Storm\Support\Error\AuditDigest;
use Throwable;

/**
 * The Postgres ProjectionStore on plain DBAL, no ORM: one `projections` row per projection, all
 * timestamps DB-generated via `clock_timestamp()`, so coordination across workers uses the
 * database clock. Runs only statements on its injected connection and, `ensure()` aside, never
 * opens a transaction, so inside a caller's transaction its writes stay atomic with the caller's;
 * `ensure()` wraps its advisory mint lock and upsert in one of its own, which nests inside any
 * caller's.
 *
 * @infection-ignore-all every method here is a SQL body whose enforcement lives in the
 *                       integration suite against a real Postgres; pure logic must not move into
 *                       this class, or it hides from the mutation field
 *
 * @see ProjectionSchema
 */
final readonly class DbalProjectionStore implements ProjectionStore
{
    private const string COLUMNS = 'name, status, last_position, mode, categories, event_classes, source_stream, source_revision, target_stream, target_prefix, lease_owner, lease_until, last_heartbeat_at, pause_until, generation, failed_at, error_message, error_class';

    /**
     * The mint lock, taken by BOTH {@see ensure()} and its {@see lockAndAssertNotRunning()} fallback:
     * the same name must hash to the same key from either call site, or the two stop serializing
     * against each other. `hashtextextended(:key, 0)` rides Postgres's 64-bit advisory space, the
     * same shape as every other advisory lock in the codebase; a prefixed key namespaces it instead
     * of a dedicated lock-class integer, which only bought 32 bits of the pair and made two
     * unrelated projection names collide far sooner than the 64-bit space needs to.
     */
    private const string MINT_LOCK_SQL = 'SELECT pg_advisory_xact_lock(hashtextextended(:key, 0))';

    private static function mintLockKey(string $name): string
    {
        return 'storm-projection-mint:'.$name;
    }

    public function __construct(
        private Connection $connection,
    ) {}

    public function ensure(string $name, string $mode, array $categories, array $eventClasses, ?StreamName $targetStream, ?string $targetPrefix = null, int $generation = 0, ?StreamName $sourceStream = null): void
    {
        // The mint is the one write a row lock cannot serialize, the row not existing yet: a
        // lifecycle op's `FOR UPDATE` on an absent name locks nothing. So every ensure takes the
        // mint advisory key first, in a transaction so the lock survives to the upsert;
        // lockAndAssertNotRunning falls back to the same key on an absent row, and a lifecycle op
        // racing a first run of one name serializes instead of interleaving with its fold.
        $this->connection->transactional(function () use ($name, $mode, $categories, $eventClasses, $targetStream, $targetPrefix, $generation, $sourceStream): void {
            $this->connection->executeStatement(self::MINT_LOCK_SQL, ['key' => self::mintLockKey($name)]);

            $this->connection->executeStatement(
                /** @lang PostgreSQL */
                <<<'SQL'
                    INSERT INTO projections (name, status, mode, categories, event_classes, source_stream, target_stream, target_prefix, generation)
                    VALUES (:name, :status, :mode, CAST(:categories AS jsonb), CAST(:eventClasses AS jsonb), :sourceStream, :targetStream, :targetPrefix, :generation)
                    ON CONFLICT (name) DO UPDATE SET
                        mode = EXCLUDED.mode,
                        categories = EXCLUDED.categories,
                        event_classes = EXCLUDED.event_classes,
                        source_stream = EXCLUDED.source_stream,
                        target_stream = EXCLUDED.target_stream,
                        target_prefix = EXCLUDED.target_prefix,
                        updated_at = clock_timestamp()
                        -- generation is NOT updated here: it stays the generation the read model was built under
                    SQL,
                [
                    'name' => $name,
                    'status' => ProjectionStatus::Idle->value,
                    'mode' => $mode,
                    'categories' => json_encode($categories, JSON_THROW_ON_ERROR),
                    'eventClasses' => json_encode($eventClasses, JSON_THROW_ON_ERROR),
                    'sourceStream' => $sourceStream?->toString(),
                    'targetStream' => $targetStream?->toString(),
                    'targetPrefix' => $targetPrefix,
                    'generation' => $generation,
                ],
            );
        });
    }

    public function acquireCheckpoint(string $name, string $owner): int
    {
        $position = $this->connection->fetchOne(
            /** @lang PostgreSQL */
            'SELECT last_position FROM projections WHERE name = :name AND lease_owner = :owner FOR UPDATE',
            ['name' => $name, 'owner' => $owner],
        );

        if ($position === false) {
            throw LeaseLost::to($name, $owner);
        }

        return (int) $position;
    }

    public function advance(string $name, int $position, string $owner): void
    {
        $advanced = $this->connection->executeStatement(
            /** @lang PostgreSQL */
            'UPDATE projections SET last_position = :position, updated_at = clock_timestamp() WHERE name = :name AND lease_owner = :owner',
            ['name' => $name, 'position' => $position, 'owner' => $owner],
            ['position' => ParameterType::INTEGER],
        );

        if ($advanced === 0) {
            throw LeaseLost::to($name, $owner);
        }
    }

    public function claimLease(string $name, string $owner, int $ttlSeconds): bool
    {
        $affected = $this->connection->executeStatement(
            /** @lang PostgreSQL */
            <<<'SQL'
                UPDATE projections SET
                    lease_owner = :owner,
                    lease_until = clock_timestamp() + (:ttl * interval '1 second'),
                    last_heartbeat_at = clock_timestamp(),
                    updated_at = clock_timestamp()
                WHERE name = :name
                  AND status IN (:runnable)
                  AND (lease_owner IS NULL OR lease_until IS NULL OR lease_until <= clock_timestamp() OR lease_owner = :owner)
                SQL,
            ['name' => $name, 'owner' => $owner, 'ttl' => $ttlSeconds, 'runnable' => ProjectionStatus::runnableValues()],
            ['ttl' => ParameterType::INTEGER, 'runnable' => ArrayParameterType::STRING],
        );

        return (int) $affected > 0;
    }

    public function markRunning(string $name, string $owner): bool
    {
        $affected = $this->connection->executeStatement(
            /** @lang PostgreSQL */
            <<<'SQL'
                UPDATE projections SET status = :status, updated_at = clock_timestamp()
                WHERE name = :name AND lease_owner = :owner AND status IN (:runnable)
                SQL,
            ['name' => $name, 'owner' => $owner, 'status' => ProjectionStatus::Running->value, 'runnable' => ProjectionStatus::runnableValues()],
            ['runnable' => ArrayParameterType::STRING],
        );

        return (int) $affected > 0;
    }

    public function renewLease(string $name, string $owner, int $ttlSeconds): void
    {
        $renewed = $this->connection->executeStatement(
            /** @lang PostgreSQL */
            <<<'SQL'
                UPDATE projections SET
                    lease_until = clock_timestamp() + (:ttl * interval '1 second'),
                    last_heartbeat_at = clock_timestamp(),
                    updated_at = clock_timestamp()
                WHERE name = :name AND lease_owner = :owner
                SQL,
            ['name' => $name, 'owner' => $owner, 'ttl' => $ttlSeconds],
            ['ttl' => ParameterType::INTEGER],
        );

        if ($renewed === 0) {
            throw LeaseLost::to($name, $owner);
        }
    }

    public function releaseLease(string $name, string $owner, ProjectionStatus $status = ProjectionStatus::Idle, ?Throwable $error = null): void
    {
        if ($status === ProjectionStatus::Failed && $error !== null) {
            // persist-safe through AuditDigest, like every other error column in the framework:
            // this UPDATE runs from the runner's finally, so a raw class name or message carrying
            // a NUL or invalid UTF-8 would fail HERE with 22021, replacing the original exception
            // the operator needs, leaving the projection unmarked and the lease held to its TTL;
            // the digest also keeps the cause chain a bare getMessage() loses
            $class = $error::class;
            if (($nul = strpos($class, "\0")) !== false) {
                $class = substr($class, 0, $nul); // an anonymous class carries NUL + path in its name
            }

            $this->connection->executeStatement(
                /** @lang PostgreSQL */
                <<<'SQL'
                    UPDATE projections SET
                        lease_owner = NULL, lease_until = NULL, status = :status,
                        failed_at = clock_timestamp(), error_class = :errorClass, error_message = :errorMessage,
                        updated_at = clock_timestamp()
                    WHERE name = :name AND lease_owner = :owner
                    SQL,
                [
                    'name' => $name,
                    'owner' => $owner,
                    'status' => $status->value,
                    'errorClass' => $class,
                    'errorMessage' => AuditDigest::digest($error),
                ],
            );

            return;
        }

        $this->connection->executeStatement(
            /** @lang PostgreSQL */
            <<<'SQL'
                UPDATE projections SET
                    lease_owner = NULL, lease_until = NULL, updated_at = clock_timestamp(),
                    -- preserve an operator's pause that landed in the runner's release window (a stop
                    -- intentionally resolves to Idle, so it is not preserved)
                    status = CASE WHEN status = :paused THEN status ELSE :status END
                WHERE name = :name AND lease_owner = :owner
                SQL,
            [
                'name' => $name,
                'owner' => $owner,
                'status' => $status->value,
                'paused' => ProjectionStatus::Paused->value,
            ],
        );
    }

    public function forceStatus(string $name, ProjectionStatus $status): void
    {
        $this->connection->executeStatement(
            /** @lang PostgreSQL */
            'UPDATE projections SET status = :status, updated_at = clock_timestamp() WHERE name = :name',
            ['name' => $name, 'status' => $status->value],
        );
    }

    /**
     * {@inheritDoc}
     *
     * The live-lease question is answered INSIDE the update, by the same statement that writes the
     * result: reading it first and deciding after is the window a worker fails in, and the decision
     * then overwrites the `failed` it left behind. `RETURNING` hands back the branch actually taken so
     * the operator message describes what happened, not what was intended.
     */
    public function requestStop(string $name, ProjectionStatus ...$from): ?ProjectionStatus
    {
        if ($from === []) {
            throw InvalidStatusTransition::withoutExpectedStates($name, ProjectionStatus::Stopping);
        }

        $landed = $this->connection->fetchOne(
            /** @lang PostgreSQL */
            <<<'SQL'
                UPDATE projections SET
                    status = CASE
                        WHEN lease_owner IS NOT NULL AND lease_until > clock_timestamp() THEN :stopping
                        ELSE :idle
                    END,
                    updated_at = clock_timestamp()
                WHERE name = :name AND status IN (:from)
                RETURNING status
                SQL,
            ['name' => $name, 'stopping' => ProjectionStatus::Stopping->value, 'idle' => ProjectionStatus::Idle->value, 'from' => ProjectionStatus::valuesOf(...$from)],
            ['from' => ArrayParameterType::STRING],
        );

        return $landed === false ? null : ProjectionStatus::from((string) $landed);
    }

    public function pause(string $name, ?int $forSeconds = null, ProjectionStatus ...$from): bool
    {
        if ($from === []) {
            throw InvalidStatusTransition::withoutExpectedStates($name, ProjectionStatus::Paused);
        }

        $params = ['name' => $name, 'status' => ProjectionStatus::Paused->value, 'from' => ProjectionStatus::valuesOf(...$from)];
        $types = ['from' => ArrayParameterType::STRING];

        if ($forSeconds !== null) {
            $params['seconds'] = $forSeconds;
            $types['seconds'] = ParameterType::INTEGER;
        }

        $horizon = $forSeconds === null ? 'NULL' : "clock_timestamp() + (:seconds * interval '1 second')";

        $affected = $this->connection->executeStatement(
            /** @lang PostgreSQL */
            'UPDATE projections SET status = :status, pause_until = '.$horizon.', updated_at = clock_timestamp() WHERE name = :name AND status IN (:from)',
            $params,
            $types,
        );

        return (int) $affected > 0;
    }

    public function resume(string $name, ProjectionStatus ...$from): bool
    {
        if ($from === []) {
            throw InvalidStatusTransition::withoutExpectedStates($name, ProjectionStatus::Idle);
        }

        $affected = $this->connection->executeStatement(
            /** @lang PostgreSQL */
            'UPDATE projections SET status = :status, pause_until = NULL, updated_at = clock_timestamp() WHERE name = :name AND status IN (:from)',
            ['name' => $name, 'status' => ProjectionStatus::Idle->value, 'from' => ProjectionStatus::valuesOf(...$from)],
            ['from' => ArrayParameterType::STRING],
        );

        return (int) $affected > 0;
    }

    public function resumeIfElapsed(string $name): void
    {
        $this->connection->executeStatement(
            /** @lang PostgreSQL */
            <<<'SQL'
                UPDATE projections SET status = :status, pause_until = NULL, updated_at = clock_timestamp()
                WHERE name = :name AND status = :fromStatus AND pause_until IS NOT NULL AND pause_until <= clock_timestamp()
                SQL,
            ['name' => $name, 'status' => ProjectionStatus::Idle->value, 'fromStatus' => ProjectionStatus::Paused->value],
        );
    }

    public function reset(string $name, int $generation = 0): bool
    {
        $affected = $this->connection->executeStatement(
            /** @lang PostgreSQL */
            <<<'SQL'
                UPDATE projections SET
                    last_position = 0, status = :status, generation = :generation,
                    lease_owner = NULL, lease_until = NULL, pause_until = NULL,
                    failed_at = NULL, error_message = NULL, error_class = NULL,
                    updated_at = clock_timestamp()
                WHERE name = :name
                SQL,
            ['name' => $name, 'status' => ProjectionStatus::Idle->value, 'generation' => $generation],
        );

        return (int) $affected > 0;
    }

    public function stampGeneration(string $name, int $generation): void
    {
        $this->connection->executeStatement(
            /** @lang PostgreSQL */
            'UPDATE projections SET generation = :generation, updated_at = clock_timestamp() WHERE name = :name',
            ['name' => $name, 'generation' => $generation],
        );
    }

    public function stampSourceRevision(string $name, int $revision): void
    {
        $this->connection->executeStatement(
            /** @lang PostgreSQL */
            'UPDATE projections SET source_revision = :revision, updated_at = clock_timestamp() WHERE name = :name',
            ['name' => $name, 'revision' => $revision],
            ['revision' => ParameterType::INTEGER],
        );
    }

    public function retry(string $name, ProjectionStatus ...$from): bool
    {
        if ($from === []) {
            throw InvalidStatusTransition::withoutExpectedStates($name, ProjectionStatus::Idle);
        }

        $affected = $this->connection->executeStatement(
            /** @lang PostgreSQL */
            <<<'SQL'
                UPDATE projections SET
                    status = :status, failed_at = NULL, error_message = NULL, error_class = NULL,
                    updated_at = clock_timestamp()
                WHERE name = :name AND status IN (:from)
                SQL,
            ['name' => $name, 'status' => ProjectionStatus::Idle->value, 'from' => ProjectionStatus::valuesOf(...$from)],
            ['from' => ArrayParameterType::STRING],
        );

        return (int) $affected > 0;
    }

    public function delete(string $name): bool
    {
        return $this->connection->executeStatement(
            /** @lang PostgreSQL */
            'DELETE FROM projections WHERE name = :name',
            ['name' => $name],
        ) > 0;
    }

    public function hasLiveLease(string $name): bool
    {
        return $this->connection->fetchOne(
            /** @lang PostgreSQL */
            <<<'SQL'
                SELECT 1 FROM projections
                WHERE name = :name AND lease_owner IS NOT NULL AND lease_until > clock_timestamp()
                SQL,
            ['name' => $name],
        ) !== false;
    }

    public function lockAndAssertNotRunning(string $name): void
    {
        $isLive = $this->connection->fetchOne(
            /** @lang PostgreSQL */
            <<<'SQL'
                SELECT CASE WHEN lease_owner IS NOT NULL AND lease_until > clock_timestamp() THEN 1 ELSE 0 END
                FROM projections WHERE name = :name FOR UPDATE
                SQL,
            ['name' => $name],
        );

        if ($isLive === false) {
            // No row, so no row lock: PostgreSQL holds none for a key that does not exist. Fall back
            // to the mint advisory key ensure() locks under, so a first run of this name blocks until
            // the op commits; the re-read covers a row minted between the probe and the lock.
            $this->connection->executeStatement(self::MINT_LOCK_SQL, ['key' => self::mintLockKey($name)]);

            $isLive = $this->connection->fetchOne(
                /** @lang PostgreSQL */
                <<<'SQL'
                    SELECT CASE WHEN lease_owner IS NOT NULL AND lease_until > clock_timestamp() THEN 1 ELSE 0 END
                    FROM projections WHERE name = :name FOR UPDATE
                    SQL,
                ['name' => $name],
            );
        }

        if ((int) $isLive === 1) {
            throw ProjectionBusy::running($name);
        }
    }

    public function findRow(string $name): ?ProjectionRow
    {
        $row = $this->connection->fetchAssociative(
            /** @lang PostgreSQL */
            'SELECT '.self::COLUMNS.' FROM projections WHERE name = :name',
            ['name' => $name],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    public function all(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            /** @lang PostgreSQL */
            'SELECT '.self::COLUMNS.' FROM projections ORDER BY name',
        );

        return array_map($this->hydrate(...), $rows);
    }

    /**
     * @param  array<string, mixed>  $row
     *
     * @throws JsonException
     */
    private function hydrate(array $row): ProjectionRow
    {
        return ProjectionRow::fromRow($row); // the column-to-field binding lives on ProjectionRow, by name
    }
}
