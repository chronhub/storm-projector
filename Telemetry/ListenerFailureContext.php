<?php

declare(strict_types=1);

namespace Storm\Projector\Telemetry;

use Throwable;

/**
 * Carries a post-commit listener failure the runner absorbed: the batch is durably committed and the
 * projection keeps running, but the side-channel, a cache purge or an app hook, threw. Surfaced through
 * observability, itself fail-open, because the failure must reach an operator without ever reaching the
 * run's outcome.
 *
 * @see ProjectorObservability::recordListenerFailure()
 */
final readonly class ListenerFailureContext
{
    public function __construct(
        /** Projection name, the registry key. */
        public string $projection,
        /** What the listener threw; the batch it followed is committed regardless. */
        public Throwable $error,
    ) {}
}
