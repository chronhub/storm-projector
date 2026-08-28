<?php

declare(strict_types=1);

namespace Storm\Projector\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Projector\Exception\InvalidRunOptions;
use Storm\Projector\Query\QueryRunMode;
use Storm\Projector\Query\QueryRunOptions;

final class QueryRunOptionsTest extends TestCase
{
    #[Test]
    public function defaults_to_a_once_fold_from_the_start(): void
    {
        $options = new QueryRunOptions;

        $this->assertSame(QueryRunMode::Once, $options->mode);
        $this->assertSame(0, $options->from);
        $this->assertNull($options->to);
        // the silent knobs, pinned by value: a drifted default changes every fold that names none
        $this->assertSame(1000, $options->batch);
        $this->assertSame(200, $options->backoffMin);
        $this->assertSame(1000, $options->backoffMax);
        $this->assertSame(100, $options->backoffStep);
        $this->assertNull($options->maxRuntime);
    }

    #[Test]
    public function accepts_every_value_sitting_exactly_on_its_floor(): void
    {
        // the guards refuse BELOW the floor, never on it: a target equal to the start, a flat
        // backoff, a zero step and a zero runtime budget are all legitimate postures
        $options = new QueryRunOptions(batch: 1, from: 3, to: 3, maxRuntime: 0, backoffMin: 1, backoffMax: 1, backoffStep: 0);

        $this->assertSame(3, $options->to);
        $this->assertSame(0, $options->backoffStep);
        $this->assertSame(1, $options->backoffMax);
        $this->assertSame(0, $options->maxRuntime);
    }

    #[Test]
    public function a_positive_runtime_budget_is_accepted(): void
    {
        $this->assertSame(5, new QueryRunOptions(maxRuntime: 5)->maxRuntime);
    }

    #[Test]
    public function accepts_a_bounded_window(): void
    {
        $options = new QueryRunOptions(mode: QueryRunMode::Drain, from: 10, to: 50);

        $this->assertSame(10, $options->from);
        $this->assertSame(50, $options->to);
    }

    #[Test]
    public function rejects_a_non_positive_batch(): void
    {
        // the page-size invariant lives here at runtime, not only in a CLI clamp; any caller is guarded.
        $this->expectException(InvalidRunOptions::class);

        // @phpstan-ignore argument.type (the zero is the point: this test defends the runtime guard behind the phpdoc)
        new QueryRunOptions(batch: 0);
    }

    #[Test]
    public function rejects_a_negative_from(): void
    {
        $this->expectException(InvalidRunOptions::class);

        new QueryRunOptions(from: -1);
    }

    #[Test]
    public function rejects_a_non_positive_target(): void
    {
        $this->expectException(InvalidRunOptions::class);

        new QueryRunOptions(to: 0);
    }

    #[Test]
    public function rejects_a_target_behind_the_start(): void
    {
        // (from, to] with to < from is an empty or reversed window, a caller mistake, not a valid read.
        $this->expectException(InvalidRunOptions::class);

        new QueryRunOptions(from: 50, to: 10);
    }

    #[Test]
    public function rejects_a_non_positive_backoff_min(): void
    {
        // a zero idle floor would busy-poll; the daemon needs a positive minimum delay.
        $this->expectException(InvalidRunOptions::class);

        new QueryRunOptions(backoffMin: 0);
    }

    #[Test]
    public function rejects_a_negative_backoff_step(): void
    {
        // step 0 is fine, constant backoff at the floor; negative would shrink the delay, nonsense.
        $this->expectException(InvalidRunOptions::class);

        new QueryRunOptions(backoffStep: -1);
    }

    #[Test]
    public function rejects_a_backoff_ceiling_below_the_floor(): void
    {
        $this->expectException(InvalidRunOptions::class);

        new QueryRunOptions(backoffMin: 1000, backoffMax: 500);
    }

    #[Test]
    public function rejects_a_negative_max_runtime(): void
    {
        // 0 is allowed, stop after the first page; negative is meaningless.
        $this->expectException(InvalidRunOptions::class);

        new QueryRunOptions(maxRuntime: -1);
    }
}
