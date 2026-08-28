<?php

declare(strict_types=1);

namespace Storm\Projector\Console;

use Override;
use Storm\Projector\Store\ProjectionLifecycleStore;
use Storm\Projector\Store\ProjectionStatus;
use Storm\Support\Console\PositiveIntOption;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Pause a projection: indefinitely, resume-only, or for `--for=<seconds>` with auto-resume after.
 *
 * Examples:
 *
 * ```bash
 * # indefinite pause, held until an explicit resume
 * bin/console storm:projection:mark:pause account_summary
 * ```
 *
 * ```bash
 * # timed pause that auto-resumes after 300 seconds
 * bin/console storm:projection:mark:pause account_summary --for=300
 * ```
 */
#[AsCommand(name: 'storm:projection:mark:pause', description: 'Pause a projection (--for=<seconds> to auto-resume after).')]
final class MarkPauseProjectionCommand extends MarkerProjectionCommand
{
    #[Override]
    protected function configure(): void
    {
        parent::configure();
        $this->addOption('for', null, InputOption::VALUE_REQUIRED, 'auto-resume after N seconds (omit for an indefinite pause)');
    }

    protected function verb(): string
    {
        return 'pause';
    }

    protected function canTransition(ProjectionStatus $status): bool
    {
        return $status->canPause();
    }

    #[Override]
    protected function validate(InputInterface $input): ?string
    {
        // Reject a mistyped --for loud, never a silent clamp where max(1, (int) 'abc') becomes 1.
        $for = $input->getOption('for');

        if ($for === null) {
            return null;
        }

        $seconds = PositiveIntOption::parse($for);

        if ($seconds === null) {
            return '--for must be a positive integer (seconds), e.g. --for=300.';
        }

        // The bound belongs to the store that writes the horizon, so both doors read the same one; past
        // it the value only surfaces as a driver-level interval error, in the channel where it reads
        // worst, and the pause never happens.
        if ($seconds > ProjectionLifecycleStore::MAX_PAUSE_SECONDS) {
            return sprintf(
                '--for is capped at %d seconds (an operational month) — to hold it longer use storm:projection:mark:stop, which needs no horizon.',
                ProjectionLifecycleStore::MAX_PAUSE_SECONDS,
            );
        }

        return null;
    }

    protected function apply(InputInterface $input, string $name, array $from): ?string
    {
        $for = $input->getOption('for');
        $forSeconds = $for === null ? null : PositiveIntOption::parse($for); // validated in validate()

        if (! $this->store->pause($name, $forSeconds, ...$from)) {
            return null; // the row left the pausable states mid-command; say so rather than claim a pause
        }

        return $forSeconds === null
            ? sprintf('Projection "%s" paused (resume with `storm:projection:mark:resume %s`).', $name, $name)
            : sprintf('Projection "%s" paused for %ds — it auto-resumes then.', $name, $forSeconds);
    }
}
