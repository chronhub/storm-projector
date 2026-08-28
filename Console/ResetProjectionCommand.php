<?php

declare(strict_types=1);

namespace Storm\Projector\Console;

use Doctrine\DBAL\Exception;
use Override;
use RuntimeException;
use Storm\Projector\Exception\ProjectionBusy;
use Storm\Projector\Exception\ProjectionHomeMismatch;
use Storm\Projector\Exception\UnknownProjection;
use Storm\Projector\Exception\UnsupportedProjection;
use Storm\Projector\Management\ProjectionManagement;
use Storm\Support\Console\DestructiveConfirmation;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Resets a projection: clears its output and rewinds the checkpoint to 0, so the next run replays from
 * scratch. Synchronous; the projection must not be running, so stop it first.
 *
 * Examples:
 *
 * ```bash
 * # rewind a stopped projection for a full replay
 * bin/console storm:projection:reset account_summary
 * ```
 */
#[AsCommand(name: 'storm:projection:reset', description: 'Reset a projection: clear its output + rewind the checkpoint to 0 — next run replays from scratch (must be stopped).')]
final class ResetProjectionCommand extends Command
{
    use DestructiveConfirmation;

    public function __construct(
        private readonly ProjectionManagement $management,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'The projection name');
        $this->configureForce('a consumer of a stream this projection produced is refused until it is reset and replayed');
    }

    /**
     * {@inheritDoc}
     *
     * @throws UnsupportedProjection when the named projection is not persistent, a QueryProjection having no output to clear
     * @throws ProjectionHomeMismatch when the name's state sits on a home its current kind does not own
     * @throws RuntimeException on a failure clearing a read-model's data
     * @throws Exception on a DBAL failure of the reset transaction
     */
    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $name = (string) $input->getArgument('name');

        $refused = $this->confirmDestructive(
            $io,
            $input,
            sprintf('Reset projection "%s"?', $name),
            'Its output is cleared and its checkpoint rewinds to zero; a derived stream loses its links, which refuses every consumer of that stream until each is reset and replayed.',
        );

        if ($refused !== null) {
            return $refused;
        }

        try {
            $stateRowExisted = $this->management->reset($name);
        } catch (UnknownProjection|ProjectionBusy $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        // pristine either way; the difference matters to an operator resetting a typo'd yet
        // registered name, who must not read success into a state row that was never there
        $io->success($stateRowExisted
            ? sprintf('Projection "%s" reset.', $name)
            : sprintf('Projection "%s" reset: output cleared; it had never run, so there was no state row to realign.', $name));

        return Command::SUCCESS;
    }
}
