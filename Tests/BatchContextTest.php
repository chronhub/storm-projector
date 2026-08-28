<?php

declare(strict_types=1);

namespace Storm\Projector\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Projector\Telemetry\BatchContext;

/**
 * The telemetry batch value's optional fields: a caught-up cycle reports zero lag, zero applied
 * and a zero watermark cost by DEFAULT, so an emitter that measured nothing publishes the honest
 * zeros rather than leftovers.
 */
final class BatchContextTest extends TestCase
{
    #[Test]
    public function a_caught_up_cycle_reports_the_honest_zeros_by_default(): void
    {
        $context = new BatchContext('accounts', 0, 12.5);

        $this->assertSame(0, $context->lag);
        $this->assertSame(0.0, $context->watermarkMs);
        $this->assertSame(0, $context->applied);
    }
}
