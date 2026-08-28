<?php

declare(strict_types=1);

namespace Storm\Projector\Telemetry;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Default ProjectorObservability, a no-op. Ships with Projector so the runner has a real dependency to
 * wire even when no Telemetry package is installed. The `Storm\Telemetry` package, opt-in, decorates or
 * replaces this alias with its real impl.
 */
#[AsAlias(ProjectorObservability::class)]
final readonly class NullProjectorObservability implements ProjectorObservability
{
    public function recordBatch(BatchContext $ctx): void {}

    public function recordRun(RunContext $ctx): void {}

    public function recordListenerFailure(ListenerFailureContext $ctx): void {}
}
