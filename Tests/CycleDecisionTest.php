<?php

declare(strict_types=1);

namespace Storm\Projector\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Projector\Run\CycleDecision;
use Storm\Projector\Run\RunOptions;
use Storm\Projector\Store\ProjectionStatus;

final class CycleDecisionTest extends TestCase
{
    #[Test]
    public function an_operator_stop_mark_ends_the_loop(): void
    {
        // a `mark stop` from any host wins the cycle, even one that was productive
        $this->assertSame(CycleDecision::Stop, self::decide(self::options(daemon: true), status: ProjectionStatus::Stopping, seen: 1));
    }

    #[Test]
    public function an_operator_pause_mark_keeps_its_pause(): void
    {
        $this->assertSame(CycleDecision::StopKeepingPause, self::decide(self::options(daemon: true), status: ProjectionStatus::Paused, seen: 1));
    }

    #[Test]
    public function a_mark_landing_with_a_rebuild_wins_the_cycle(): void
    {
        // the operator's intent outranks the refusal: the same cycle may carry both, and a pause
        // must not be overwritten by a Failed, nor a stop by one
        $this->assertSame(CycleDecision::StopKeepingPause, self::decide(self::options(daemon: true), status: ProjectionStatus::Paused, sourceRebuilt: true, seen: 1));
        $this->assertSame(CycleDecision::Stop, self::decide(self::options(daemon: true), status: ProjectionStatus::Stopping, sourceRebuilt: true, seen: 1));
    }

    #[Test]
    public function a_rebuilt_source_refuses_the_cycle(): void
    {
        $this->assertSame(CycleDecision::RefuseRebuilt, self::decide(self::options(daemon: true), sourceRebuilt: true, seen: 1));
    }

    #[Test]
    public function a_rebuilt_source_outranks_the_run_budget(): void
    {
        // refusing beats stopping quietly: an exhausted budget must not mask the rebuild
        $this->assertSame(CycleDecision::RefuseRebuilt, self::decide(self::options(daemon: true), sourceRebuilt: true, stopRequested: true, seen: 1));
    }

    #[Test]
    public function a_vanished_row_decides_nothing_at_the_boundary(): void
    {
        $this->assertSame(CycleDecision::AwaitEvents, self::decide(self::options(daemon: true), status: null, seen: 0));
    }

    #[Test]
    public function a_stop_request_wins_over_every_mode(): void
    {
        // a signal or an exhausted runtime budget ends the loop whatever the mode would decide
        $this->assertSame(CycleDecision::Stop, self::decide(self::options(), stopRequested: true, seen: 3, lastPosition: 1));
        $this->assertSame(CycleDecision::Stop, self::decide(self::options(daemon: true), stopRequested: true, seen: 3, lastPosition: 1));
        $this->assertSame(CycleDecision::Stop, self::decide(self::options(drain: true), stopRequested: true, seen: 0, lastPosition: 1));
        $this->assertSame(CycleDecision::Stop, self::decide(self::options(to: 10), stopRequested: true, seen: 3, lastPosition: 1));
    }

    #[Test]
    public function a_target_reached_stops_even_with_events_still_flowing(): void
    {
        // the bound is inclusive: a checkpoint AT the target stops, more matching rows or not
        $this->assertSame(CycleDecision::Stop, self::decide(self::options(to: 10), seen: 3, lastPosition: 10));
    }

    #[Test]
    public function a_short_read_below_the_target_stops(): void
    {
        // caught up to the safe head below the target: nothing more to scan this run
        $this->assertSame(CycleDecision::Stop, self::decide(self::options(to: 10), seen: 0, lastPosition: 4));
    }

    #[Test]
    public function an_unreached_target_advances_at_once(): void
    {
        $this->assertSame(CycleDecision::Advance, self::decide(self::options(to: 10), seen: 2, lastPosition: 9));
    }

    #[Test]
    public function once_stops_after_its_single_batch(): void
    {
        // productive or empty, the flagless run never loops
        $this->assertSame(CycleDecision::Stop, self::decide(self::options(), seen: 3, lastPosition: 3));
        $this->assertSame(CycleDecision::Stop, self::decide(self::options(), seen: 0, lastPosition: 0));
    }

    #[Test]
    public function a_caught_up_drain_stops(): void
    {
        $this->assertSame(CycleDecision::Stop, self::decide(self::options(drain: true), seen: 0, lastPosition: 7));
    }

    #[Test]
    public function a_caught_up_daemon_awaits_events(): void
    {
        $this->assertSame(CycleDecision::AwaitEvents, self::decide(self::options(daemon: true), seen: 0, lastPosition: 7));
    }

    #[Test]
    public function a_productive_continuous_cycle_reads_again_with_a_fresh_backoff(): void
    {
        $this->assertSame(CycleDecision::Productive, self::decide(self::options(daemon: true), seen: 1, lastPosition: 8));
        $this->assertSame(CycleDecision::Productive, self::decide(self::options(drain: true), seen: 1, lastPosition: 8));
    }

    /**
     * The healthy-cycle baseline: a `Running` row, no rebuild, no stop request, so each test names
     * only the value its behavior turns on.
     */
    private static function decide(
        RunOptions $options,
        ?ProjectionStatus $status = ProjectionStatus::Running,
        bool $sourceRebuilt = false,
        bool $stopRequested = false,
        int $seen = 0,
        int $lastPosition = 0,
    ): CycleDecision {
        return CycleDecision::next($options, $status, $sourceRebuilt, $stopRequested, $seen, $lastPosition);
    }

    private static function options(bool $daemon = false, bool $drain = false, ?int $to = null): RunOptions
    {
        return new RunOptions(owner: 'test', daemon: $daemon, drain: $drain, to: $to);
    }
}
