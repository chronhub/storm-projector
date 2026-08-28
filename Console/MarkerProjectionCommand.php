<?php

declare(strict_types=1);

namespace Storm\Projector\Console;

use Doctrine\DBAL\Exception;
use JsonException;
use Override;
use Storm\Projector\Exception\ProjectionHomeMismatch;
use Storm\Projector\Store\ProjectionLifecycleStore;
use Storm\Projector\Store\ProjectionStatus;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Shared base for the projection status-control commands `mark:pause`, `mark:resume` and `mark:stop`:
 * each subclass declares the verb it performs, the transition it allows via `canTransition`, delegating
 * to a ProjectionStatus predicate, the single source of the state machine, and the store mutation via
 * `apply`. This base resolves the row, validates the transition, and delegates; a failed projection gets
 * a hint toward `retry`/`reset`.
 *
 * The read-then-write is a race by construction: an operator command decides on a status it read a
 * moment ago, and a worker failing in between makes that decision stale. So the validated states travel
 * INTO the mutation, which is compare-and-set: `apply()` returns null when the row left them, and the
 * command reports that instead of claiming a success it did not achieve. Without it, `mark:stop` on a
 * projection that failed mid-command would write `idle` over the `failed`, telling the operator "nothing
 * to stop" while erasing the state that keeps a poison batch from being re-run.
 *
 * Excluded from service loading, being abstract; the concrete subclasses are the registered commands.
 */
abstract class MarkerProjectionCommand extends Command
{
    public function __construct(
        protected readonly ProjectionLifecycleStore $store,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'The projection name');
    }

    /**
     * The verb performed, for the rejection message: `pause`, `resume` or `stop`.
     */
    abstract protected function verb(): string;

    /**
     * Whether the transition is valid from the current status; a `ProjectionStatus` predicate.
     */
    abstract protected function canTransition(ProjectionStatus $status): bool;

    /**
     * Perform the store mutation, compare-and-set against `$from`, and return the success message.
     *
     * @param  list<ProjectionStatus>  $from  the states this verb may legally start from, carried INTO
     *                                        the statement so the row cannot leave them between the
     *                                        validation above and the write
     * @return string|null null when the row was no longer in `$from`, so nothing was written
     *
     * @throws Exception on a DBAL failure of the status mutation
     */
    abstract protected function apply(InputInterface $input, string $name, array $from): ?string;

    /**
     * Validate command input before touching the store: return the operator-facing error to reject with,
     * exiting INVALID, or null to proceed. Default: nothing to validate.
     */
    protected function validate(InputInterface $input): ?string
    {
        return null;
    }

    /**
     * {@inheritDoc}
     *
     * @throws ProjectionHomeMismatch when the name's state sits on a home its current kind does not own;
     *                                the routed store probes before every marker mutation
     * @throws JsonException when the projection's stored row is malformed
     * @throws Exception on a DBAL failure of the status read / mutation
     */
    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $name = (string) $input->getArgument('name');

        $error = $this->validate($input);
        if ($error !== null) {
            $io->error($error);

            return Command::INVALID;
        }

        $row = $this->store->findRow($name);
        if ($row === null) {
            $io->error(sprintf('No projection "%s".', $name));

            return Command::FAILURE;
        }

        if (! $this->canTransition($row->status)) {
            $io->error(sprintf('Cannot "%s" a projection in status "%s".', $this->verb(), $row->status->value));
            if ($row->status === ProjectionStatus::Failed) {
                $io->writeln('A failed projection recovers via "storm:projection:retry" (resume from the checkpoint) or "storm:projection:reset" (replay from scratch).');
            }

            return Command::FAILURE;
        }

        $applied = $this->apply($input, $name, $this->expectedStates());

        if ($applied === null) {
            // the row left the validated states between the read above and the write: without the
            // compare-and-set, a worker failing right there would have its `failed` overwritten by this
            // command's stale decision
            $io->error(sprintf(
                'Projection "%s" changed status while this command ran, so nothing was written. Check its status and retry.',
                $name,
            ));

            return Command::FAILURE;
        }

        $io->success($applied);

        return Command::SUCCESS;
    }

    /**
     * Every status this verb may legally start from, DERIVED from `canTransition()`, the same predicate
     * the read above validates with, so the pre-check and the compare-and-set cannot drift apart. A second
     * hand-kept list is exactly how they would.
     *
     * @return list<ProjectionStatus>
     */
    private function expectedStates(): array
    {
        return array_values(array_filter(
            ProjectionStatus::cases(),
            $this->canTransition(...),
        ));
    }
}
