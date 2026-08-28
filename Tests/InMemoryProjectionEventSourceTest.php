<?php

declare(strict_types=1);

namespace Storm\Projector\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Chronicler\Evolution\IdentityEventTypeMapper;
use Storm\Chronicler\Record\EventRecord;
use Storm\Chronicler\Record\SequencePosition;
use Storm\Clock\PointInTime;
use Storm\Message\Message;
use Storm\Projector\Run\ProjectionReadWindow;
use Storm\Projector\Run\RunOptions;
use Storm\Projector\Run\RunProfile;
use Storm\Projector\Run\RunState;
use Storm\Projector\Run\Stage\ReadBatch;
use Storm\Projector\Testing\InMemory\InMemoryProjectionEventSource;
use Storm\Projector\Tests\Fixture\SomethingHappened;
use Storm\Projector\Tests\Fixture\StubFilteredProjection;

/**
 * The fixture's OWN contract, the half a stage test never reaches: the write surface a consumer
 * scripts a multi-cycle run with. `append()` grows the feed under a running projection and
 * `advanceWatermarkTo()` moves the frontier, so a test can hold a tail undecided, fold what is
 * certified, then release the rest and fold again.
 *
 * The reduced contract is asserted where it bites: appended records are readable only once the
 * frontier reaches them, which is how the fixture models a producer that has not certified its
 * tail. Sequence gaps, locks and isolation are not modeled here and belong to the PostgreSQL
 * integration suite.
 *
 * @see ReadBatchStageTest
 */
final class InMemoryProjectionEventSourceTest extends TestCase
{
    #[Test]
    public function appended_records_land_after_the_ones_already_fed(): void
    {
        $source = new InMemoryProjectionEventSource([$this->record(1), $this->record(2)], watermark: 4);

        $source->append([$this->record(3), $this->record(4)]);

        $this->assertSame([1, 2, 3, 4], $this->positions($source->read($this->window(max: 4))));
    }

    #[Test]
    public function an_appended_tail_stays_unread_while_the_frontier_holds(): void
    {
        // the undecided-tail posture: the records exist in the feed, the frontier does not vouch
        // for them yet, and a run capped on that frontier must not fold them
        $source = new InMemoryProjectionEventSource([$this->record(1)], watermark: 1);
        $state = $this->state();

        (new ReadBatch($source))($state, static fn (): int => 99);
        $state->lastPosition = 1;
        $state->resetBatch(); // the pipeline clears the per-batch scratch between two cycles
        $source->append([$this->record(2), $this->record(3)]);

        $this->assertSame(0, (new ReadBatch($source))($state, static fn (): int => 99));
        $this->assertSame([], $state->records);
    }

    #[Test]
    public function raising_the_frontier_releases_the_tail_to_the_next_cycle(): void
    {
        $source = new InMemoryProjectionEventSource([$this->record(1)], watermark: 1);
        $state = $this->state();
        $state->lastPosition = 1;
        $source->append([$this->record(2), $this->record(3)]);

        $source->advanceWatermarkTo(3);
        (new ReadBatch($source))($state, static fn (): int => 99);

        $this->assertSame(3, $state->scanHead);
        $this->assertSame([2, 3], $this->positions($state->records));
    }

    #[Test]
    public function fixtures_must_be_strictly_ordered(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new InMemoryProjectionEventSource([$this->record(2), $this->record(1)]);
    }

    #[Test]
    public function an_append_rejects_a_duplicate_boundary_without_partly_mutating_the_feed(): void
    {
        $source = new InMemoryProjectionEventSource([$this->record(1)], watermark: 3);

        try {
            $source->append([$this->record(2), $this->record(2)]);
            self::fail('the malformed append should have been rejected');
        } catch (InvalidArgumentException) {
            self::assertSame([1], $this->positions($source->read($this->window(max: 3))));
        }
    }

    #[Test]
    public function advancing_to_the_same_watermark_is_idempotent_but_moving_backwards_is_refused(): void
    {
        $source = new InMemoryProjectionEventSource(watermark: 3);
        $source->advanceWatermarkTo(3);

        self::assertSame(3, $source->watermark(null));

        $this->expectException(InvalidArgumentException::class);

        $source->advanceWatermarkTo(2);
    }

    #[Test]
    public function a_negative_initial_watermark_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new InMemoryProjectionEventSource(watermark: -1);
    }

    /**
     * @param  list<EventRecord>  $records
     * @return list<int>
     */
    private function positions(array $records): array
    {
        return array_map(static fn (EventRecord $record): int => (int) $record->position->toString(), $records);
    }

    private function window(int $max): ProjectionReadWindow
    {
        return new ProjectionReadWindow(after: 0, max: $max, limit: 1000, categories: [], types: []);
    }

    private function state(): RunState
    {
        return new RunState(RunProfile::for(
            new StubFilteredProjection('s'),
            new RunOptions(owner: 'test'),
            eventTypeMapper: new IdentityEventTypeMapper,
        ));
    }

    private function record(int $position): EventRecord
    {
        return new EventRecord(
            new Message(new SomethingHappened('r-'.$position)),
            SequencePosition::fromInt($position),
            PointInTime::from('2026-01-01T00:00:00.000000+00:00'),
            ['id' => 'r-'.$position],
        );
    }
}
