<?php

declare(strict_types=1);

namespace Storm\Projector\Tests;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Projector\Exception\DuplicateProjection;
use Storm\Projector\Registry\ProjectionRegistry;
use Storm\Projector\Run\ProjectionLane;
use Storm\Projector\Run\ProjectionLanes;
use Storm\Projector\Store\HomedProjectionStore;
use Storm\Projector\Store\ProjectionRow;
use Storm\Projector\Store\ProjectionStatus;
use Storm\Projector\Store\ProjectionStore;

final class HomedProjectionStoreTest extends TestCase
{
    #[Test]
    public function a_single_home_is_read_once_and_preserves_database_name_order(): void
    {
        $store = $this->createMock(ProjectionStore::class);
        $rows = [$this->row('10'), $this->row('9')];
        $store->expects(self::once())->method('all')->willReturn($rows);
        $catalog = new HomedProjectionStore(
            ProjectionLanes::single($store, $this->createStub(Connection::class)),
            new ProjectionRegistry,
        );

        self::assertSame($rows, $catalog->all());
    }

    #[Test]
    public function distinct_homes_are_both_read_and_merged_in_name_order(): void
    {
        $alpha = $this->row('alpha');
        $beta = $this->row('beta');
        $events = $this->createMock(ProjectionStore::class);
        $events->expects(self::once())->method('all')->willReturn([$beta]);
        $models = $this->createMock(ProjectionStore::class);
        $models->expects(self::once())->method('all')->willReturn([$alpha]);
        $catalog = new HomedProjectionStore(new ProjectionLanes(
            new ProjectionLane($events, $this->createStub(Connection::class)),
            new ProjectionLane($models, $this->createStub(Connection::class)),
        ), new ProjectionRegistry);

        self::assertSame([$alpha, $beta], $catalog->all());
    }

    #[Test]
    public function the_same_name_on_both_homes_is_refused(): void
    {
        $events = $this->createStub(ProjectionStore::class);
        $events->method('all')->willReturn([$this->row('shared')]);
        $models = $this->createStub(ProjectionStore::class);
        $models->method('all')->willReturn([$this->row('shared')]);
        $catalog = new HomedProjectionStore(new ProjectionLanes(
            new ProjectionLane($events, $this->createStub(Connection::class)),
            new ProjectionLane($models, $this->createStub(Connection::class)),
        ), new ProjectionRegistry);

        $this->expectException(DuplicateProjection::class);
        $catalog->all();
    }

    private function row(string $name): ProjectionRow
    {
        return new ProjectionRow($name, ProjectionStatus::Idle, 0, 'filtered', [], [], null, 0, null, null, null, null, null, null, 0);
    }
}
