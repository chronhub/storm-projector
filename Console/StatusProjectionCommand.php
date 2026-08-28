<?php

declare(strict_types=1);

namespace Storm\Projector\Console;

use Doctrine\DBAL\Exception;
use JsonException;
use Override;
use Storm\Chronicler\Exception\StorageFailure;
use Storm\Projector\Freshness\ProjectionWaiter;
use Storm\Projector\Store\ProjectionCatalog;
use Storm\Projector\Store\ProjectionRow;
use Storm\Projector\Store\ProjectionStatus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function Symfony\Component\String\u;

/**
 * Inspects projection state from the `projections` table: one projection by name or all. Read-only.
 *
 * Examples:
 *
 * ```bash
 * # all projections at a glance
 * bin/console storm:projection:status
 * ```
 *
 * ```bash
 * # one projection by name
 * bin/console storm:projection:status account_summary
 * ```
 */
#[AsCommand(name: 'storm:projection:status', description: 'Show projection status — one (by name) or all.')]
final class StatusProjectionCommand extends Command
{
    public function __construct(
        private readonly ProjectionCatalog $store,
        private readonly ProjectionWaiter $waiter,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::OPTIONAL, 'A projection name (omit to list all)');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Print the machine-readable rows (full error class and message, untruncated)');
    }

    /**
     * {@inheritDoc}
     *
     * @throws JsonException when a projection's stored row is malformed
     * @throws StorageFailure when the waiter's lag / at-head reads fail at the storage level; it wraps
     *                        the DBAL cause at its consuming boundary
     * @throws Exception on a DBAL failure of the row and lease reads issued here
     */
    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $name = $input->getArgument('name');

        if (is_string($name) && $name !== '') {
            $found = $this->store->findRow($name);
            $rows = $found === null ? [] : [$found];
        } else {
            $rows = $this->store->all();
        }

        $json = $input->getOption('json') === true;

        if ($json) {
            // the document travels even when empty: a scripted caller reads the shape, and the exit
            // code carries the verdict, so an empty answer is not an error to parse around
            $output->writeln(json_encode(
                ['projections' => array_map(fn (ProjectionRow $row): array => $this->machineRow($row), $rows)],
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return $rows === [] && is_string($name) && $name !== '' ? Command::FAILURE : Command::SUCCESS;
        }

        if ($rows === []) {
            if (is_string($name) && $name !== '') {
                // a name was supplied and matched nothing: the world said no, so the exit says so.
                // A mistyped name answering a quiet success is what lets `status $NAME && deploy`
                // roll on over a projection that is not there
                $io->error(sprintf('No projection "%s".', $name));

                return Command::FAILURE;
            }

            // no name, so no filter: an empty catalog is a legitimate answer, not a refusal
            $io->warning('No projections.');

            return Command::SUCCESS;
        }

        $io->table(
            ['Name', 'Status', 'Position', 'Lag', 'At head?', 'Mode', 'Lease', 'Heartbeat', 'Target', 'Failed at', 'Error'],
            array_map(fn (ProjectionRow $row): array => [
                $row->name,
                self::statusCell($row),
                (string) $row->lastPosition,
                (string) $this->waiter->lag($row->name),
                $this->waiter->isAtHead($row->name) ? 'yes' : 'no',
                $row->mode,
                $this->leaseCell($row),
                $row->lastHeartbeatAt ?? '—',
                self::targetCell($row),
                $row->failedAt ?? '—',
                self::errorCell($row),
            ], $rows),
        );

        if (count($rows) === 1) {
            $this->renderDetail($io, $rows[array_key_first($rows)]);
        }

        return Command::SUCCESS;
    }

    /**
     * The facts a sweep table cannot carry, shown when one projection is named.
     *
     * `generation` and `source_revision` are two of the three grounds a run refuses on, and the
     * refusal itself sends the operator here: a stand-down naming this command, over a table that
     * showed neither, answered the question with the question. The third ground, the topology drift,
     * is already the `mode` and `Target` cells.
     *
     * The filter and the source belong here rather than in the table for width alone: a category list
     * is app-sized, and the sweep is read across every projection at once.
     */
    private function renderDetail(SymfonyStyle $io, ProjectionRow $row): void
    {
        $io->definitionList(
            ['Generation' => (string) $row->generation],
            ['Source' => $row->sourceStream === null
                ? '—'
                : sprintf('%s @r%d', $row->sourceStream->toString(), $row->sourceRevision)],
            ['Categories' => $row->categories === [] ? '—' : implode(', ', $row->categories)],
            ['Event classes' => $row->eventClasses === [] ? '—' : implode(', ', $row->eventClasses)],
        );
    }

    /**
     * Status, enriched with the timed-pause horizon as `paused (until T)`; an indefinite pause stays a
     * plain `paused`.
     */
    private static function statusCell(ProjectionRow $row): string
    {
        if ($row->status === ProjectionStatus::Paused && $row->pauseUntil !== null) {
            return sprintf('paused (until %s)', $row->pauseUntil);
        }

        return $row->status->value;
    }

    /**
     * The producer's output: a link projection's fixed target stream, a fan-out's prefix rendered
     * `prefix*`, since its many computed targets live under it, or `—` for a read model with no stream
     * output.
     */
    private static function targetCell(ProjectionRow $row): string
    {
        if ($row->targetStream !== null) {
            return $row->targetStream->toString();
        }

        return $row->targetPrefix !== null ? $row->targetPrefix.'*' : '—';
    }

    /**
     * The lease, with its live/expired truth: the row shows `lease_owner` even after a worker crashed, so
     * resolve `hasLiveLease`, the SQL clock, to say whether it actually holds.
     *
     * @throws Exception
     */
    private function leaseCell(ProjectionRow $row): string
    {
        if ($row->leaseOwner === null) {
            return '—';
        }

        // the horizon, not just the verdict: `expired` alone leaves the operator wondering whether the
        // worker died a second ago or an hour ago, and that is the difference between waiting and
        // reclaiming
        return sprintf(
            '%s (%s%s)',
            $row->leaseOwner,
            $this->store->hasLiveLease($row->name) ? 'live' : 'expired',
            $row->leaseUntil === null ? '' : ' until '.$row->leaseUntil,
        );
    }

    /**
     * Compact "class: message" for the status table when failed; the full backtrace lives in
     * telemetry/logs.
     */
    private static function errorCell(ProjectionRow $row): string
    {
        if ($row->errorClass === null && $row->errorMessage === null) {
            return '—';
        }

        $class = $row->errorClass !== null
            ? u($row->errorClass)->afterLast('\\')->toString() // short class name, or the whole string if unqualified
            : '';

        $message = u((string) $row->errorMessage)
            ->replaceMatches('/[\r\n]+/', ' ') // collapse newlines for a single table cell
            ->truncate(60, '…')
            ->toString();

        return trim($class.': '.$message, ': ');
    }

    /**
     * One row for the machine channel: the stored shape, plus the three answers the table computes.
     *
     * `lag` and `at_head` are not on the row, they are read against the safe head, and a scripted
     * caller asking "is this projection caught up" needs exactly them; leaving them to the human table
     * would make the json the poorer of the two channels on the only question worth scripting.
     *
     * `lease_live` joins them for the same reason, and its absence was a parity defect rather than a
     * missing detail: the human cell asks the SQL clock whether the lease actually holds and prints
     * `live` or `expired`, so a scripted reader could not tell a working projection from one whose
     * worker died while a reader of the table could. A null owner has no liveness to report and stays
     * null, never false, which would claim an expired lease where there is no lease at all.
     *
     * @return array<string, mixed>
     *
     * @throws Exception on a DBAL read failure while resolving the lease
     */
    private function machineRow(ProjectionRow $row): array
    {
        return $row->toArray() + [
            'lag' => $this->waiter->lag($row->name),
            'at_head' => $this->waiter->isAtHead($row->name),
            'lease_live' => $row->leaseOwner === null ? null : $this->store->hasLiveLease($row->name),
        ];
    }
}
