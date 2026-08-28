<?php

declare(strict_types=1);

namespace Storm\Projector\Console;

use Override;
use Storm\Projector\Exception\InvalidRunOptions;
use Storm\Projector\Exception\ProjectionOutOfDate;
use Storm\Projector\Exception\StaleMigratedContent;
use Storm\Projector\Exception\UnknownProjection;
use Storm\Projector\Exception\UnsupportedProjection;
use Storm\Projector\Run\ProjectionRunner;
use Storm\Projector\Run\RunOptions;
use Storm\Projector\Run\RunOutcome;
use Storm\Projector\Run\StandDown;
use Storm\Projector\Store\ProjectionStatus;
use Storm\Support\Console\PositiveIntOption;
use Storm\Support\Console\TimeLimitOption;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Runs a projection through the `ProjectionRunner`: `--drain` catches up to the safe head then stops,
 * `--daemon` keeps polling, and neither runs a single batch.
 *
 * Implements SignalableCommandInterface so SIGTERM/SIGINT stop the loop gracefully; the current batch
 * finishes and its checkpoint commits before the runner exits.
 *
 * `owner` is an argument, not a hidden option, because it conditions lease coordination: distinct per
 * concurrent worker, defaulted to `hostname:pid`; the resolved owner is echoed at run start, so the
 * intent is visible, not buried. The contention rule lives on `RunOptions`.
 *
 * Examples:
 *
 * ```bash
 * # one batch then stop (the default mode)
 * bin/console storm:projection:run account_summary
 * ```
 *
 * ```bash
 * # catch up to the safe head, then stop
 * bin/console storm:projection:run account_summary --drain
 * ```
 *
 * ```bash
 * # long-running worker, distinct owner per process
 * bin/console storm:projection:run account_summary worker-1 --daemon --lease-ttl=120
 * ```
 *
 * ```bash
 * # bounded catch-up to a fixed position (snapshot tooling)
 * bin/console storm:projection:run account_summary --to=50000
 * ```
 *
 * @see \Symfony\Component\Console\Command\SignalableCommandInterface
 */
#[AsCommand(name: 'storm:projection:run', description: 'Run a projection: drain (catch up) / daemon / once.')]
final class RunProjectionCommand extends Command
{
    private bool $stopRequested = false;

    public function __construct(
        private readonly ProjectionRunner $runner,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'The projection name')
            ->addArgument('owner', InputArgument::OPTIONAL, 'Lease owner id — MUST be distinct per concurrent worker (the lease is re-entrant for the same owner). Default: hostname:pid')
            ->addOption('drain', null, InputOption::VALUE_NONE, 'Catch up to the safe head, then stop')
            ->addOption('daemon', null, InputOption::VALUE_NONE, 'Keep running, polling for new events')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Events per batch', '1000')
            ->addOption('lease-ttl', null, InputOption::VALUE_REQUIRED, 'Lease TTL in seconds', '60')
            ->addOption('time-limit', null, InputOption::VALUE_REQUIRED, 'Stop after N wall-clock seconds (default 3600 — a supervisor relaunch is the memory hygiene); 0 = unlimited, explicit', '3600')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Run up to this position (id) then stop — not with --drain/--daemon')
            ->addOption('allow-stale', null, InputOption::VALUE_NONE, 'Run even if the read model is out of date (built under an older generation())');
    }

    /**
     * @return list<int>
     */
    #[Override]
    public function getSubscribedSignals(): array
    {
        return array_values(array_filter(
            [defined('SIGTERM') ? SIGTERM : null, defined('SIGINT') ? SIGINT : null],
            static fn (?int $signal): bool => $signal !== null,
        ));
    }

    #[Override]
    public function handleSignal(int $signal, int|false $previousExitCode = false): int|false
    {
        $this->stopRequested = true;

        return false; // don't exit now; let the runner finish the current batch and stop cleanly
    }

    /**
     * {@inheritDoc}
     *
     * @throws Throwable on a run failure not handled here, a transient deadlock, a DBAL or JSON error, or
     *                   an unrecoverable projection error, surfaced to the console runner
     */
    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $name = (string) $input->getArgument('name');

        // Reject a mistyped numeric option loud, never PHP's silent `(int)` 0: a typo'd --time-limit
        // casts to 0, which RunOptions permits, a silent misbehavior. RunOptions still validates as the
        // library invariant; this is the operator-facing message.
        $batch = PositiveIntOption::parse($input->getOption('batch'));
        if ($batch === null) {
            $io->error('--batch must be a positive integer (events per batch), e.g. --batch=1000.');

            return Command::INVALID;
        }

        $leaseTtl = PositiveIntOption::parse($input->getOption('lease-ttl'));
        if ($leaseTtl === null) {
            $io->error('--lease-ttl must be a positive integer (seconds), e.g. --lease-ttl=60.');

            return Command::INVALID;
        }

        $timeLimit = TimeLimitOption::parse($input->getOption('time-limit'));
        if ($timeLimit === null) {
            $io->error(sprintf('--time-limit must be 0 (unlimited, explicit) or a positive integer of seconds up to %d.', TimeLimitOption::MAX_SECONDS));

            return Command::INVALID;
        }
        // RunOptions spells unlimited as null; the option spells it 0, the one word an operator has
        // to type on purpose
        $maxRuntime = $timeLimit === 0 ? null : $timeLimit;

        $to = $this->optionalPositiveInt($input->getOption('to'));
        if ($to === false) {
            $io->error('--to must be a positive integer (a stream position).');

            return Command::INVALID;
        }

        try {
            $options = new RunOptions(
                owner: $this->owner($input),
                batch: $batch,
                daemon: (bool) $input->getOption('daemon'),
                drain: (bool) $input->getOption('drain'),
                allowStale: (bool) $input->getOption('allow-stale'),
                leaseTtl: $leaseTtl,
                maxRuntime: $maxRuntime,
                to: $to,
            );

            $io->text(sprintf('Running "%s" as owner "%s" (%s).', $name, $options->owner, $this->modeLabel($options)));

            $outcome = $this->runner->run($name, $options, fn (): bool => $this->stopRequested);
        } catch (InvalidRunOptions $e) {
            // a mode combination the flags cannot mean together: the invocation is malformed, and
            // its exit says so, like every other option guard above
            $io->error($e->getMessage());

            return Command::INVALID;
        } catch (UnknownProjection|UnsupportedProjection|ProjectionOutOfDate|StaleMigratedContent $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if (! $outcome->started()) {
            // A run that never began is not a finished catch-up, and saying so is what keeps `--drain`
            // usable as a deployment gate. Non-zero on purpose, including under a supervisor: the loop
            // was already hot, exit zero then relaunch then refuse, and it was silent about it.
            $io->error($this->standDownMessage($name, $outcome));

            return Command::FAILURE;
        }

        $io->success(sprintf('Projection "%s" finished.', $name));

        return Command::SUCCESS;
    }

    /**
     * A nullable positive-int option: null when unset, the parsed value when valid, or false when the
     * caller passed a non-positive integer, since a mistyped option must fail loud, never silently cast
     * to 0.
     */
    private function optionalPositiveInt(mixed $raw): int|false|null
    {
        if ($raw === null) {
            return null;
        }

        return PositiveIntOption::parse($raw) ?? false;
    }

    private function owner(InputInterface $input): string
    {
        $owner = $input->getArgument('owner');

        if (is_string($owner) && $owner !== '') {
            return $owner;
        }

        // Process-distinct default so two runs on the same host contend on the lease; a shared owner is
        // re-entrant because claimLease matches `lease_owner = :owner`, so both would run the same projection.
        // Pass the argument for a stable identity, e.g. a restart that re-takes its own lease before expiry.
        return (gethostname() ?: 'cli').':'.getmypid();
    }

    private function modeLabel(RunOptions $options): string
    {
        return match (true) {
            $options->daemon => 'daemon',
            $options->drain => 'drain',
            $options->to !== null => 'to '.$options->to,
            default => 'once',
        };
    }

    /**
     * Why the run stood down, and the verb that unblocks it when there is one.
     *
     * The three causes call for opposite gestures, which is why they are told apart: an operator's own
     * hold waits for their verb, another worker holding the lease needs nothing at all, and a mark that
     * won the claim race means the operator's own verb took effect.
     */
    private function standDownMessage(string $name, RunOutcome $outcome): string
    {
        return match ($outcome->standDown) {
            StandDown::NotRunnable => sprintf(
                'Projection "%s" did not run: its status is "%s". %s',
                $name,
                $outcome->status->value ?? 'unknown',
                match ($outcome->status) {
                    ProjectionStatus::Paused => 'Lift it with storm:projection:mark:resume.',
                    ProjectionStatus::Failed => 'Clear the failure with storm:projection:retry, or start over with storm:projection:reset.',
                    ProjectionStatus::Stopping => 'A worker is shutting it down; wait for it, or realign a dead one with storm:projection:mark:stop.',
                    default => 'Inspect it with storm:projection:status.',
                },
            ),
            StandDown::LeaseHeld => sprintf(
                'Projection "%s" did not run: another worker holds its lease, so it is already being caught up. Nothing to do here; storm:projection:status names the owner.',
                $name,
            ),
            StandDown::MarkedMidClaim => sprintf(
                'Projection "%s" did not run: a mark:pause or mark:stop landed while it was claiming, and the mark won. That verb took effect; storm:projection:status shows where it left the row.',
                $name,
            ),
            null => sprintf('Projection "%s" did not run.', $name),
        };
    }
}
