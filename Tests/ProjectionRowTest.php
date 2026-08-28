<?php

declare(strict_types=1);

namespace Storm\Projector\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Projector\Store\ProjectionRow;
use Storm\Projector\Store\ProjectionStatus;

final class ProjectionRowTest extends TestCase
{
    #[Test]
    public function the_machine_shape_keeps_an_absent_source_stream_as_null(): void
    {
        $row = new ProjectionRow(
            name: 'account_balance',
            status: ProjectionStatus::Running,
            lastPosition: 0,
            mode: 'filtered',
            categories: [],
            eventClasses: [],
            sourceStream: null,
            sourceRevision: 0,
            targetStream: null,
            targetPrefix: null,
            leaseOwner: null,
            leaseUntil: null,
            lastHeartbeatAt: null,
            pauseUntil: null,
            generation: 1,
        );

        self::assertNull($row->toArray()['source_stream']);
    }
}
