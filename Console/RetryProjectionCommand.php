<?php

declare(strict_types=1);

namespace Storm\Projector\Console;

use Doctrine\DBAL\Exception;
use JsonException;
use Override;
use Storm\Projector\Exception\ProjectionBusy;
use Storm\Projector\Exception\ProjectionHomeMismatch;
use Storm\Projector\Exception\ProjectionNotFailed;
use Storm\Projector\Exception\UnknownProjection;
use Storm\Projector\Management\ProjectionManagement;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Recovers a failed projection by clearing the failure context and setting status to `idle`, keeping the
 * checkpoint so the next run catches up from where it stopped, the failed batch rolled back. Use after
 * fixing the cause. For a full rebuild from scratch, use `storm:projection:reset` instead.
 *
 * Examples:
 *
 * ```bash
 * # clear a failed projection and resume from its checkpoint
 * bin/console storm:projection:retry account_summary
 * ```
 */
#[AsCommand(name: 'storm:projection:retry', description: 'Retry a failed projection: clear the failure, keep the checkpoint, resume (use reset for a full replay).')]
final class RetryProjectionCommand extends Command
{
    public function __construct(
        private readonly ProjectionManagement $management,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'The projection name');
    }

    /**
     * {@inheritDoc}
     *
     * @throws ProjectionHomeMismatch when the name's state sits on a home its current kind does not own
     * @throws JsonException when the projection's stored row is malformed
     * @throws Exception on a DBAL failure of the retry
     */
    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $name = (string) $input->getArgument('name');

        try {
            $this->management->retry($name);
        } catch (UnknownProjection|ProjectionNotFailed|ProjectionBusy $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Projection "%s" cleared — resumes from its checkpoint on the next run.', $name));

        return Command::SUCCESS;
    }
}
