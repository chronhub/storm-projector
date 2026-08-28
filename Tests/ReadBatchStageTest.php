<?php

declare(strict_types=1);

namespace Storm\Projector\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Chronicler\Evolution\IdentityEventTypeMapper;
use Storm\Chronicler\Record\EventRecord;
use Storm\Chronicler\Record\SequencePosition;
use Storm\Clock\PointInTime;
use Storm\Message\Message;
use Storm\Projector\Run\RunOptions;
use Storm\Projector\Run\RunProfile;
use Storm\Projector\Run\RunState;
use Storm\Projector\Run\Stage\ReadBatch;
use Storm\Projector\Testing\InMemory\InMemoryProjectionEventSource;
use Storm\Projector\Tests\Fixture\SomethingHappened;
use Storm\Projector\Tests\Fixture\StubFilteredProjection;

/**
 * The read stage's OWN rules, judged over the in-memory source: the caught-up short-circuit at
 * its exact boundary, the target cap below the frontier, and the page landing in the run state.
 * How a frontier is probed and how a selection queries the store are the source's business,
 * judged in the integration suite.
 */
final class ReadBatchStageTest extends TestCase
{
    #[Test]
    public function caught_up_exactly_at_the_frontier_short_circuits_without_reading(): void
    {
        // the boundary is inclusive: a checkpoint AT the watermark has nothing new below it
        $stage = new ReadBatch(new InMemoryProjectionEventSource([$this->record(7)], watermark: 7));
        $state = $this->state();
        $state->lastPosition = 7;

        $result = $stage($state, static fn (): int => 99);

        $this->assertSame(0, $result);
        $this->assertSame([], $state->records);
        $this->assertSame(7, $state->scanHead); // the lag metric still learns the frontier
    }

    #[Test]
    public function the_target_caps_the_read_below_the_frontier(): void
    {
        // `--to` bounds the run, never past the watermark: the scan window closes at the target
        // and the records above it stay unread
        $stage = new ReadBatch(new InMemoryProjectionEventSource([$this->record(4), $this->record(5), $this->record(6)], watermark: 10));
        $state = $this->state(to: 5);

        $result = $stage($state, static fn (): int => 99);

        $this->assertSame(99, $result);
        $this->assertSame(5, $state->scanMax);
        $this->assertSame(10, $state->scanHead);
        $this->assertSame([4, 5], array_map(static fn (EventRecord $r): int => (int) $r->position->toString(), $state->records));
    }

    #[Test]
    public function a_page_lands_in_the_run_state_bounded_by_the_batch_knob(): void
    {
        $stage = new ReadBatch(new InMemoryProjectionEventSource([$this->record(1), $this->record(2), $this->record(3)], watermark: 3));
        $state = $this->state(batch: 2);

        $result = $stage($state, static fn (): int => 99);

        $this->assertSame(99, $result);
        $this->assertSame(3, $state->scanMax);
        $this->assertCount(2, $state->records);
    }

    #[Test]
    public function the_window_excludes_the_checkpoint_position_itself(): void
    {
        // `after` is exclusive: the record AT the checkpoint was already folded by the batch that
        // put the checkpoint there
        $stage = new ReadBatch(new InMemoryProjectionEventSource([$this->record(4), $this->record(5)], watermark: 5));
        $state = $this->state();
        $state->lastPosition = 4;

        $stage($state, static fn (): int => 99);

        $this->assertSame([5], array_map(static fn (EventRecord $r): int => (int) $r->position->toString(), $state->records));
    }

    #[Test]
    public function a_bare_source_starts_with_nothing_readable(): void
    {
        // the fixture's default frontier is zero: appended records stay unreadable until the test
        // raises the watermark, the undecided-tail posture by default
        $source = new InMemoryProjectionEventSource([$this->record(1)]);

        $this->assertSame(0, $source->watermark(null));
        $this->assertSame([], (new ReadBatch($source))($this->state(), static fn (): int => 99) === 0 ? [] : ['read happened']);
    }

    /**
     * @param  positive-int  $batch
     */
    private function state(?int $to = null, int $batch = 1000): RunState
    {
        return new RunState(RunProfile::for(
            new StubFilteredProjection('s'),
            new RunOptions(owner: 'test', batch: $batch, to: $to),
            eventTypeMapper: new IdentityEventTypeMapper, ));
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
