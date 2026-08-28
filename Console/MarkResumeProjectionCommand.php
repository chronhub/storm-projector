<?php

declare(strict_types=1);

namespace Storm\Projector\Console;

use Storm\Projector\Store\ProjectionStatus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Resume a paused projection, timed or indefinite: back to `idle`, the pause horizon cleared.
 *
 * Examples:
 *
 * ```bash
 * # un-pause a paused projection
 * bin/console storm:projection:mark:resume account_summary
 * ```
 */
#[AsCommand(name: 'storm:projection:mark:resume', description: 'Resume a paused projection.')]
final class MarkResumeProjectionCommand extends MarkerProjectionCommand
{
    protected function verb(): string
    {
        return 'resume';
    }

    protected function canTransition(ProjectionStatus $status): bool
    {
        return $status->canResume();
    }

    protected function apply(InputInterface $input, string $name, array $from): ?string
    {
        // the validated states travel INTO the statement, same as pause/stop: derived from
        // canTransition(), never a list the store would hardcode on our behalf
        return $this->store->resume($name, ...$from)
            ? sprintf('Projection "%s" resumed.', $name)
            : null;
    }
}
