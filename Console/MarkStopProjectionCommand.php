<?php

declare(strict_types=1);

namespace Storm\Projector\Console;

use Storm\Projector\Store\ProjectionStatus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Stop a running projection: a cross-process request the daemon observes next cycle, then exits
 * gracefully, its current batch's checkpoint committing, back to `idle`.
 *
 * Resolves against the lease truth, not the possibly-stale status: with no live lease there is no worker
 * to observe a `Stopping` request, a crashed worker having left `running` behind, so the status realigns
 * straight to `idle`, claimable again, instead of writing a `Stopping` nothing would ever resolve.
 * Re-stopping a `Stopping` row is idempotent, and the same no-live-lease path un-bricks a row stuck
 * there.
 *
 * Examples:
 *
 * ```bash
 * # ask a running daemon to stop gracefully next cycle
 * bin/console storm:projection:mark:stop account_summary
 * ```
 */
#[AsCommand(name: 'storm:projection:mark:stop', description: 'Stop a running projection (it exits gracefully next cycle).')]
final class MarkStopProjectionCommand extends MarkerProjectionCommand
{
    protected function verb(): string
    {
        return 'stop';
    }

    protected function canTransition(ProjectionStatus $status): bool
    {
        return $status->canStop();
    }

    protected function apply(InputInterface $input, string $name, array $from): ?string
    {
        // the liveness question and the write are ONE statement: asking first and deciding after is the
        // window a worker fails in, and the answer would then overwrite the `failed` it left
        $landed = $this->store->requestStop($name, ...$from);

        return match ($landed) {
            ProjectionStatus::Stopping => sprintf('Projection "%s" marked stopping.', $name),
            ProjectionStatus::Idle => sprintf('Projection "%s" had no live worker — nothing to stop, status realigned to idle.', $name),
            default => null,
        };
    }
}
