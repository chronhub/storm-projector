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
 * Deletes a projection: drops its output, a read-model table or link rows, and its state row.
 * Synchronous; the projection must not be running. Destructive.
 *
 * On a `MigratedReadModel` the table itself SURVIVES, a Doctrine migration owning its schema, but its
 * content is emptied, since that content is storm's, event-derived and replayable. Leaving the rows would
 * make this command's answer a lie: the class stays registered, so the next run restarts at zero and
 * refolds the whole history over them.
 *
 * Examples:
 *
 * ```bash
 * # drop a stopped projection's output and tracking row
 * bin/console storm:projection:delete account_summary
 * ```
 */
#[AsCommand(name: 'storm:projection:delete', description: 'Delete a projection: drop its output + remove it entirely (must be stopped).')]
final class DeleteProjectionCommand extends Command
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
     * @throws UnsupportedProjection when the named projection is not persistent, a QueryProjection having no output to drop
     * @throws ProjectionHomeMismatch when the name's state sits on a home its current kind does not own
     * @throws RuntimeException on a failure dropping a read-model's schema
     * @throws Exception on a DBAL failure of the delete transaction
     */
    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $name = (string) $input->getArgument('name');

        $refused = $this->confirmDestructive(
            $io,
            $input,
            sprintf('Delete projection "%s"?', $name),
            'Its output goes, and a derived stream loses its links, which refuses every consumer of that stream until each is reset and replayed.',
        );

        if ($refused !== null) {
            return $refused;
        }

        try {
            $this->management->delete($name);
        } catch (UnknownProjection|ProjectionBusy $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Projection "%s" deleted.', $name));

        return Command::SUCCESS;
    }
}
