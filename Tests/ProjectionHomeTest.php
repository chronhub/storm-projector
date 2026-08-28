<?php

declare(strict_types=1);

namespace Storm\Projector\Tests;

use Doctrine\DBAL\Connection;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Storm\Projector\Run\PipelineFactory;
use Storm\Projector\Run\ProjectionEventSource;
use Storm\Projector\Run\ProjectionHome;
use Storm\Projector\Run\ProjectionLane;
use Storm\Projector\Run\ProjectionLanes;
use Storm\Projector\Run\Stage\AcquireCheckpoint;
use Storm\Projector\Run\Stage\ApplyBatch;
use Storm\Projector\Run\Stage\Checkpoint;
use Storm\Projector\Run\Stage\ReadBatch;
use Storm\Projector\Store\ProjectionStore;
use Storm\Projector\Tests\Fixture\StubDerivedConsumer;
use Storm\Projector\Tests\Fixture\StubFanOutProjection;
use Storm\Projector\Tests\Fixture\StubFilteredProjection;
use Storm\Projector\Tests\Fixture\StubLinkProjection;

/**
 * The homing rule and its carrier types. The rule is the split's whole safety story: a
 * projection's checkpoint commits with ITS writes, so link producers, which write event_links
 * events-side by necessity since the derived read joins it with event_store, must home on the
 * events connection, and everything that writes read models homes on the store connection.
 * Wrong homing would silently reopen the two-tx contradiction the design dissolved; it is
 * pinned here per kind, including the subtle one: a DerivedStream CONSUMER writes a read
 * model, so it homes store-side while its join-read is events-side either way.
 */
final class ProjectionHomeTest extends TestCase
{
    #[Test]
    public function link_producers_home_on_the_events_connection(): void
    {
        $this->assertSame(ProjectionHome::Events, ProjectionHome::of(new StubLinkProjection));
        $this->assertSame(ProjectionHome::Events, ProjectionHome::of(new StubFanOutProjection));
    }

    #[Test]
    public function read_model_writers_home_on_the_store_connection(): void
    {
        $this->assertSame(ProjectionHome::ReadModelStore, ProjectionHome::of(new StubFilteredProjection('rm')));
        // the subtle one: a derived-stream CONSUMER writes a read model, a store-side home,
        // while its join-read runs through the StreamReader on the events connection
        $this->assertSame(ProjectionHome::ReadModelStore, ProjectionHome::of(new StubDerivedConsumer));
    }

    #[Test]
    public function lanes_select_by_home(): void
    {
        $events = new ProjectionLane($this->store(), $this->connection());
        $readModels = new ProjectionLane($this->store(), $this->connection());
        $lanes = new ProjectionLanes($events, $readModels);

        $this->assertSame($events, $lanes->laneFor(new StubLinkProjection));
        $this->assertSame($readModels, $lanes->laneFor(new StubDerivedConsumer));
    }

    #[Test]
    public function distinct_collapses_the_single_database_mode(): void
    {
        // same connection INSTANCE on both lanes: union surfaces must
        // read the one projections table once, not twice
        $shared = $this->connection();
        $lanes = new ProjectionLanes(
            new ProjectionLane($this->store(), $shared),
            new ProjectionLane($this->store(), $shared),
        );
        $this->assertCount(1, $lanes->distinct());

        $split = new ProjectionLanes(
            new ProjectionLane($this->store(), $this->connection()),
            new ProjectionLane($this->store(), $this->connection()),
        );
        $this->assertCount(2, $split->distinct());
    }

    #[Test]
    public function a_store_only_lane_refuses_the_run_path_with_the_wiring_story(): void
    {
        $lane = new ProjectionLane($this->store(), $this->connection()); // no pipeline: list/waiter/management wiring

        try {
            $lane->runPipeline();
            $this->fail('expected the store-only lane to refuse the run path');
        } catch (LogicException $e) {
            $this->assertStringContainsString('store-only', $e->getMessage());
        }
    }

    #[Test]
    public function single_wires_one_kit_for_both_homes(): void
    {
        $store = $this->store();
        $connection = $this->connection();
        $pipeline = new PipelineFactory(
            new AcquireCheckpoint($store),
            new ReadBatch($this->createStub(ProjectionEventSource::class)),
            new ApplyBatch($connection),
            new Checkpoint($store),
        );

        $lanes = ProjectionLanes::single($store, $connection, $pipeline);

        $this->assertSame($lanes->events, $lanes->readModels);
        $this->assertSame($store, $lanes->events->store);
        $this->assertSame($pipeline, $lanes->events->runPipeline());
        $this->assertCount(1, $lanes->distinct());
    }

    #[Test]
    public function the_single_database_mode_trusts_the_declared_home_without_probing(): void
    {
        // one distinct connection means ONE projections table: the other lane object is the same
        // storage, so a probe there would read the projection's own row as a foreign-home mismatch
        $shared = $this->connection();
        $readModels = new ProjectionLane($this->probeFreeStore(), $shared);
        $lanes = new ProjectionLanes(new ProjectionLane($this->probeFreeStore(), $shared), $readModels);

        $lanes->assertHomedOn('accounts', $readModels);
        $this->addToAssertionCount(1); // reaching here IS the assertion: no probe, no mismatch

    }

    private function probeFreeStore(): ProjectionStore
    {
        $store = $this->createStub(ProjectionStore::class);
        $store->method('findRow')->willThrowException(new RuntimeException('the single-database shortcut must not probe'));

        return $store;
    }

    private function store(): ProjectionStore
    {
        return $this->createStub(ProjectionStore::class);
    }

    private function connection(): Connection
    {
        return $this->createStub(Connection::class);
    }
}
