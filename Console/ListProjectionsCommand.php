<?php

declare(strict_types=1);

namespace Storm\Projector\Console;

use Doctrine\DBAL\Exception;
use JsonException;
use Override;
use Storm\Projector\Definition\PersistentProjection;
use Storm\Projector\Definition\Projection;
use Storm\Projector\Definition\ProjectionKind;
use Storm\Projector\Definition\QueryProjection;
use Storm\Projector\Registry\ProjectionRegistry;
use Storm\Projector\Store\ProjectionCatalog;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Lists the projections the code declares, the `#[AsProjection]`-tagged services the ProjectionRegistry
 * collects, with their declared build generation via `generation()`. Pure code-side inventory;
 * complements `storm:projection:status`, which reports the runtime `projections` rows. `--with-db`
 * additionally reads the stored generation and flags out-of-date projections: when declared and stored
 * differ, the read model was built by an older generation, so rebuild with `reset`.
 *
 * Examples:
 *
 * ```bash
 * # code-side inventory: declared projections and their generation
 * bin/console storm:projection:list
 * ```
 *
 * ```bash
 * # also read the stored generation and flag out-of-date projections
 * bin/console storm:projection:list --with-db
 * ```
 *
 * @see StatusProjectionCommand
 */
#[AsCommand(name: 'storm:projection:list', description: 'List the registered projections and their declared generation.')]
final class ListProjectionsCommand extends Command
{
    public function __construct(
        private readonly ProjectionRegistry $registry,
        private readonly ProjectionCatalog $store,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Print the machine-readable inventory');
        $this->addOption('with-db', null, InputOption::VALUE_NONE, 'Also read the stored generation from the projections table and flag out-of-date ones');
    }

    /**
     * {@inheritDoc}
     *
     * @throws JsonException when a stored projection row is malformed, only with --with-db
     * @throws Exception on a DBAL failure reading the stored rows, only with --with-db
     */
    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $withDb = (bool) $input->getOption('with-db');

        $projections = $this->registry->all();

        if ($input->getOption('json') === true) {
            $output->writeln(json_encode([
                'projections' => array_map(function (Projection $projection) use ($withDb): array {
                    $declared = $projection instanceof PersistentProjection ? $projection->generation() : null;
                    $row = [
                        'name' => $projection->name(),
                        'type' => ProjectionKind::of($projection)->value,
                        'generation' => $declared,
                    ];

                    if ($withDb) {
                        $stored = $this->store->findRow($projection->name())?->generation;
                        $row['stored_generation'] = $stored;
                        // named for what it compares: a run also refuses on topology drift and on a
                        // rebuilt derived source, neither of which this reads
                        $row['generation_matches'] = $declared !== null && $stored !== null && $stored !== 0 && $stored === $declared;
                    }

                    return $row;
                }, array_values($projections)),
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        if ($projections === []) {
            $io->warning('No projections registered.');

            return Command::SUCCESS;
        }

        // The header names the axis it MEASURES, not the question an operator brings. Generation is
        // one of three reasons a run refuses, topology drift and a rebuilt derived source being the
        // other two, and neither is read here; a column promising "up to date" answered yes over a
        // projection that would refuse to start.
        $headers = $withDb
            ? ['Name', 'Type', 'Generation', 'Stored', 'Generation matches?']
            : ['Name', 'Type', 'Generation'];

        $rows = [];
        foreach ($projections as $projection) {
            $declared = $projection instanceof PersistentProjection ? $projection->generation() : null;
            $row = [$projection->name(), ProjectionKind::of($projection)->value, $declared === null ? '—' : (string) $declared];

            if ($withDb) {
                $stored = $this->store->findRow($projection->name())?->generation;
                $row[] = $stored === null ? '—' : (string) $stored;
                $row[] = self::freshnessCell($declared, $stored);
            }

            $rows[] = $row;
        }

        $io->table($headers, $rows);

        return Command::SUCCESS;
    }

    private static function freshnessCell(?int $declared, ?int $stored): string
    {
        return match (true) {
            $declared === null => '—',          // a QueryProjection has no stored generation
            $stored === null => 'not built',    // no row yet, never run
            $stored === 0 => 'unstamped',       // will adopt the declared generation on its first run
            $stored === $declared => 'yes',
            default => 'OUT OF DATE',           // built by an older generation, so rebuild with reset
        };
    }
}
