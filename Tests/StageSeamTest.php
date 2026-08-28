<?php

declare(strict_types=1);

namespace Storm\Projector\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The pipeline stages speak the event-source seam only: a stage naming a store concrete, a
 * safe-head implementation or a DBAL filter is an integration adapter smuggled into a stage,
 * exactly the coupling `ProjectionEventSource` exists to prevent.
 */
final class StageSeamTest extends TestCase
{
    #[Test]
    public function the_stages_name_no_store_concrete_and_no_filter(): void
    {
        $offenders = [];

        foreach (glob(dirname(__DIR__).'/Run/Stage/*.php') ?: [] as $file) {
            $content = (string) file_get_contents($file);
            foreach (['SafeHeadAdvancer', 'StreamReader', 'QueryFilter', 'Storm\\EventLinks'] as $token) {
                if (str_contains($content, $token)) {
                    $offenders[] = basename($file).' names '.$token;
                }
            }
        }

        $this->assertSame([], $offenders);
    }
}
