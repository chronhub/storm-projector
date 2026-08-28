<?php

declare(strict_types=1);

namespace Storm\Projector\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Chronicler\Evolution\IdentityEventTypeMapper;
use Storm\Projector\Run\RunOptions;
use Storm\Projector\Run\RunProfile;
use Storm\Projector\Run\RunState;
use Storm\Projector\Tests\Fixture\StubFilteredProjection;

/**
 * The per-batch scratch state between the pipeline stages: born idling at the backoff floor, and
 * `resetBatch` returns every batch field to zero so a deadlock retry re-reads cleanly with no
 * double counting.
 */
final class RunStateTest extends TestCase
{
    #[Test]
    public function the_batch_reset_zeroes_every_field_a_retry_would_double_count(): void
    {
        $state = new RunState(RunProfile::for(new StubFilteredProjection('s'), new RunOptions(owner: 'test'), eventTypeMapper: new IdentityEventTypeMapper));
        $state->records = [];
        $state->applied = 7;
        $state->lastApplied = 42;
        $state->scanMax = 99;
        $state->scanHead = 120;
        $state->watermarkMs = 3.5;

        $state->resetBatch();

        $this->assertSame([], $state->records);
        $this->assertSame(0, $state->applied);
        $this->assertSame(0, $state->lastApplied);
        $this->assertSame(0, $state->scanMax);
        $this->assertSame(0, $state->scanHead);
        $this->assertSame(0.0, $state->watermarkMs);
    }

    #[Test]
    public function a_fresh_state_idles_at_the_backoff_floor(): void
    {
        $state = new RunState(RunProfile::for(new StubFilteredProjection('s'), new RunOptions(owner: 'test', backoffMin: 350), eventTypeMapper: new IdentityEventTypeMapper));

        $this->assertSame(350, $state->idleMs);
    }
}
