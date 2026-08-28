<?php

declare(strict_types=1);

namespace Storm\Projector\Tests;

use Doctrine\DBAL\Connection;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use Storm\Projector\Definition\MigratedReadModelBehavior;
use Storm\Projector\Exception\CannotDropMigratedReadModel;
use Storm\Projector\Exception\MigratedTableMissing;
use Storm\Projector\Tests\Fixture\DemoMigratedReadModel;

/**
 * The lifecycle a MigratedReadModel inherits from the behavior trait. The whole point of throwing
 * rather than a silent no-op is that the safety is assertable: the table-missing fail-fast and the drop
 * refusal are both pinned here, as is the `hasContent()` contract that an ABSENT table reads as empty,
 * initialize() owning that refusal. The `clear()` path is a plain TRUNCATE, exercised by the dogfood
 * reset against a real DB; the content probe's real EXISTS runs in the integration suite.
 */
final class MigratedReadModelBehaviorTest extends TestCase
{
    #[Test]
    public function initialize_passes_when_the_migrated_table_exists(): void
    {
        new DemoMigratedReadModel(tablePresent: true)->initialize($this->createStub(Connection::class));

        $this->expectNotToPerformAssertions(); // it must simply not throw
    }

    #[Test]
    public function initialize_throws_when_the_migrated_table_is_missing(): void
    {
        $this->expectException(MigratedTableMissing::class);

        new DemoMigratedReadModel(tablePresent: false)->initialize($this->createStub(Connection::class));
    }

    #[Test]
    #[Group('adversarial')]
    public function a_schema_qualified_table_name_is_refused_at_initialize(): void
    {
        // the two probes disagree on a qualified name: to_regclass parses the qualifier, while the
        // quoted TRUNCATE and content probe wrap the whole string in ONE identifier; without this
        // gate the declaration passed initialize() and failed later at clear() with a confusing
        // missing-relation error, far from the declaration that caused it
        $host = new readonly class()
        {
            use MigratedReadModelBehavior;

            protected function tableName(): string
            {
                return 'reporting.rm_orders';
            }

            protected function tableExists(Connection $tx): bool
            {
                return true;
            }
        };

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/reporting\.rm_orders.*search_path/');

        $host->initialize($this->createStub(Connection::class));
    }

    #[Test]
    public function drop_throws_because_a_migration_owns_the_table(): void
    {
        $this->expectException(CannotDropMigratedReadModel::class);

        new DemoMigratedReadModel(tablePresent: true)->drop($this->createStub(Connection::class));
    }

    #[Test]
    public function has_content_reads_a_missing_table_as_empty(): void
    {
        // initialize() owns the missing-table refusal and its message; the stale-content gate calling
        // hasContent() first must not preempt it with a raw undefined-table error, so the probe
        // must never reach the connection: a query here IS the raw error the guard exists to avoid
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willThrowException(new RuntimeException('undefined table'));

        self::assertFalse(new DemoMigratedReadModel(tablePresent: false)->hasContent($connection));
    }

    #[Test]
    public function has_content_answers_the_exists_probe_when_the_table_is_present(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn(true);

        self::assertTrue(new DemoMigratedReadModel(tablePresent: true)->hasContent($connection));
    }

    #[Test]
    public function the_host_supplies_no_connection_of_its_own(): void
    {
        // the trait's guarantee since the store split: every method is HANDED its home connection, so
        // a host physically cannot bind the wrong database into its lifecycle
        $this->assertSame(
            ['tablePresent'],
            array_map(
                static fn (ReflectionProperty $p): string => $p->getName(),
                new ReflectionClass(DemoMigratedReadModel::class)->getProperties(),
            ),
        );
    }

    #[Test]
    public function return_default_generation_as_one(): void
    {
        $this->assertEquals(1, new DemoMigratedReadModel(tablePresent: true)->generation());
    }
}
