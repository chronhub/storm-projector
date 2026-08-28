<?php

declare(strict_types=1);

namespace Storm\Projector\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Projector\Exception\InvalidRunOptions;
use Storm\Projector\Run\RunOptions;

final class RunOptionsTest extends TestCase
{
    #[Test]
    public function rejects_daemon_with_drain(): void
    {
        $this->expectException(InvalidRunOptions::class);

        new RunOptions(owner: 'test', daemon: true, drain: true);
    }

    #[Test]
    public function rejects_a_target_with_drain(): void
    {
        $this->expectException(InvalidRunOptions::class);

        new RunOptions(owner: 'test', drain: true, to: 5);
    }

    #[Test]
    public function rejects_a_target_with_daemon(): void
    {
        $this->expectException(InvalidRunOptions::class);

        new RunOptions(owner: 'test', daemon: true, to: 5);
    }

    #[Test]
    public function accepts_a_plain_target(): void
    {
        $this->assertSame(5, new RunOptions(owner: 'test', to: 5)->to);
    }

    #[Test]
    public function the_defaults_are_the_documented_tempo(): void
    {
        // the silent knobs, pinned by value: a drifted default changes every runner that names none
        $options = new RunOptions(owner: 'test');

        $this->assertSame(1000, $options->batch);
        $this->assertFalse($options->daemon);
        $this->assertFalse($options->drain);
        $this->assertFalse($options->allowStale);
        $this->assertSame(60, $options->leaseTtl);
        $this->assertSame(200, $options->backoffMin);
        $this->assertSame(1000, $options->backoffMax);
        $this->assertSame(100, $options->backoffStep);
        $this->assertNull($options->maxRuntime);
        $this->assertNull($options->to);
    }

    #[Test]
    public function accepts_every_value_sitting_exactly_on_its_floor(): void
    {
        // the guards refuse BELOW the floor, never on it: a flat backoff, a zero step, a zero
        // runtime budget and a ceiling equal to the floor are all legitimate postures
        $options = new RunOptions(owner: 'test', batch: 1, leaseTtl: 1, backoffMin: 1, backoffMax: 1, backoffStep: 0, maxRuntime: 0, to: 1);

        $this->assertSame(1, $options->batch);
        $this->assertSame(0, $options->backoffStep);
        $this->assertSame(1, $options->backoffMax);
        $this->assertSame(0, $options->maxRuntime);
    }

    #[Test]
    public function each_continuous_mode_is_accepted_alone_with_a_runtime_budget(): void
    {
        // only the COMBINATION is incoherent: either mode alone, budgeted, must construct
        $this->assertTrue(new RunOptions(owner: 'test', daemon: true, maxRuntime: 5)->daemon);
        $this->assertTrue(new RunOptions(owner: 'test', drain: true, maxRuntime: 5)->drain);
    }

    #[Test]
    public function rejects_a_non_positive_batch(): void
    {
        // the invariant lives here at runtime, not only in the CLI command's clamp; any caller is guarded.
        $this->expectException(InvalidRunOptions::class);

        // @phpstan-ignore argument.type (the zero is the point: this test defends the runtime guard behind the phpdoc)
        new RunOptions(owner: 'test', batch: 0);
    }

    #[Test]
    public function rejects_a_non_positive_lease_ttl(): void
    {
        $this->expectException(InvalidRunOptions::class);

        new RunOptions(owner: 'test', leaseTtl: 0);
    }

    #[Test]
    public function rejects_a_non_positive_target(): void
    {
        $this->expectException(InvalidRunOptions::class);

        new RunOptions(owner: 'test', to: 0);
    }

    #[Test]
    public function rejects_an_empty_owner(): void
    {
        // owner is the lease holder identity; an empty one breaks the lease guard, WHERE lease_owner = ''.
        $this->expectException(InvalidRunOptions::class);

        new RunOptions(owner: '');
    }

    #[Test]
    public function rejects_a_non_positive_backoff_min(): void
    {
        // a zero idle floor would busy-poll; the daemon needs a positive minimum delay.
        $this->expectException(InvalidRunOptions::class);

        new RunOptions(owner: 'test', backoffMin: 0);
    }

    #[Test]
    public function rejects_a_negative_backoff_step(): void
    {
        // step 0 is fine, constant backoff at the floor; negative would shrink the delay, nonsense.
        $this->expectException(InvalidRunOptions::class);

        new RunOptions(owner: 'test', backoffStep: -1);
    }

    #[Test]
    public function rejects_a_backoff_ceiling_below_the_floor(): void
    {
        // the idle delay grows from min toward max; max < min is an incoherent range.
        $this->expectException(InvalidRunOptions::class);

        new RunOptions(owner: 'test', backoffMin: 1000, backoffMax: 500);
    }

    #[Test]
    public function rejects_a_negative_max_runtime(): void
    {
        // 0 is allowed, stop after the first batch; negative is meaningless.
        $this->expectException(InvalidRunOptions::class);

        new RunOptions(owner: 'test', maxRuntime: -1);
    }
}
