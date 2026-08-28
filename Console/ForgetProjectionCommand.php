<?php

declare(strict_types=1);

namespace Storm\Projector\Console;

use Doctrine\DBAL\Exception;
use JsonException;
use Override;
use Storm\Projector\Exception\ProjectionBusy;
use Storm\Projector\Exception\ProjectionNotOrphaned;
use Storm\Projector\Exception\UnknownProjection;
use Storm\Projector\Management\ProjectionManagement;
use Storm\Support\Console\DestructiveConfirmation;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Forgets an orphaned projection, a `projections` row whose class was removed, no longer in the registry,
 * which `storm:projection:delete` cannot resolve. Drops the tracking row and its link rows; the
 * read-model table, if any, is the operator's to drop, since its shape lived only in the removed code.
 * Synchronous; the projection must not be running. Destructive.
 *
 * Examples:
 *
 * ```bash
 * # clear the leftover row of a removed projection
 * bin/console storm:projection:forget legacy_summary
 * ```
 */
#[AsCommand(name: 'storm:projection:forget', description: 'Forget an orphan (its code was removed): clear the leftover tracking row + link rows.')]
final class ForgetProjectionCommand extends Command
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
        $this->addArgument('name', InputArgument::REQUIRED, 'The orphaned projection name');
        $this->configureForce('the tracking row and the link rows do not come back without a replay');
    }

    /**
     * {@inheritDoc}
     *
     * @throws JsonException when the projection's stored row is malformed
     * @throws Exception on a DBAL failure of the forget transaction
     */
    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $name = (string) $input->getArgument('name');

        $refused = $this->confirmDestructive(
            $io,
            $input,
            sprintf('Forget orphan projection "%s"?', $name),
            'Its tracking row and any link rows go; a consumer of a stream it produced is refused until it is reset and replayed.',
        );

        if ($refused !== null) {
            return $refused;
        }

        try {
            $this->management->forget($name);
        } catch (ProjectionNotOrphaned|UnknownProjection|ProjectionBusy $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Orphan projection "%s" forgotten (tracking row + any link rows removed).', $name));
        $io->note('If it had a read-model table, drop it manually — its name/shape lived only in the removed code.');

        return Command::SUCCESS;
    }
}
