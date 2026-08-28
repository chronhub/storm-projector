<?php

declare(strict_types=1);

namespace Storm\Projector\Console;

use Doctrine\DBAL\Exception;
use LogicException;
use Override;
use Storm\Projector\Definition\DerivedStreamProjection;
use Storm\Projector\Definition\FanOutLinkProjection;
use Storm\Projector\Definition\LinkProjection;
use Storm\Projector\Exception\CannotRetire;
use Storm\Projector\Exception\ProjectionBusy;
use Storm\Projector\Exception\ProjectionHomeMismatch;
use Storm\Projector\Exception\UnknownProjection;
use Storm\Projector\Management\ProjectionManagement;
use Storm\Projector\Registry\ProjectionRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Retires a stream producer, a `LinkProjection` or a `FanOutLinkProjection`: removes its bookkeeping,
 * the state row and checkpoint, but keeps its derived stream, `event_links`, for consumers, detaching
 * the producer, as opposed to `storm:projection:delete` which drops the stream. Synchronous; the
 * projection must not be running. Orphaning a stream, leaving it producer-less, is deliberate, so
 * `--orphan-stream` is required.
 *
 * The two kinds are siblings, not one a subtype of the other, so what survives is named differently:
 * a link keeps one fixed `targetStream()`, a fan-out keeps every stream under its `targetPrefix()`.
 *
 * Post-retire, it scans the registry for declared consumers of what survived, a DerivedStreamProjection
 * folding it, and warns when none exist, a likely sign you meant delete. The scan only sees registered
 * consumers; ad-hoc `inspect`, replay/export, or external SQL readers are not detected.
 *
 * Examples:
 *
 * ```bash
 * # detach a producer, keeping its derived stream for consumers
 * bin/console storm:projection:retire account_links --orphan-stream
 * ```
 */
#[AsCommand(name: 'storm:projection:retire', description: 'Retire a stream producer: remove it but keep its derived stream for consumers (must be stopped).')]
final class RetireProjectionCommand extends Command
{
    public function __construct(
        private readonly ProjectionManagement $management,
        private readonly ProjectionRegistry $registry,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'The stream-producer name: a link projection or a fan-out');
        $this->addOption('orphan-stream', null, InputOption::VALUE_NONE, 'Acknowledge that the derived stream is left with no producer (orphaned)');
    }

    /**
     * {@inheritDoc}
     *
     * @throws ProjectionHomeMismatch when the name's state sits on a home its current kind does not own
     * @throws LogicException when the retire guard admitted a kind this render cannot name, a wiring
     *                        fault between the two, never a caller's mistake
     * @throws Exception on a DBAL failure removing the state row
     */
    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $name = (string) $input->getArgument('name');
        $orphanStream = (bool) $input->getOption('orphan-stream');

        try {
            $rowRemoved = $this->management->retire($name, $orphanStream);
        } catch (UnknownProjection|CannotRetire|ProjectionBusy $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $projection = $this->registry->get($name); // code still registered; only the row was removed

        // A fan-out is a SIBLING of LinkProjection, with a prefix where the other has a single target.
        // Reading it through `instanceof LinkProjection` alone named the projection ITSELF as the
        // surviving stream, so the success line pointed at a stream that does not exist and the
        // consumer scan could never match, firing the delete advice on every fan-out retire.
        if ($projection instanceof FanOutLinkProjection) {
            $subject = $projection->targetPrefix();
            $consumes = static fn (string $source): bool => str_starts_with($source, $subject);
            $io->success($rowRemoved
                ? sprintf('Projection "%s" retired — its derived streams under "%s" are kept (orphaned).', $name, $subject)
                : sprintf('Projection "%s" had no state row to retire; its derived streams under "%s" stand as they were.', $name, $subject));
        } elseif ($projection instanceof LinkProjection) {
            $subject = $projection->targetStream()->toString();
            $consumes = static fn (string $source): bool => $source === $subject;
            $io->success($rowRemoved
                ? sprintf('Projection "%s" retired — its derived stream "%s" is kept (orphaned).', $name, $subject)
                : sprintf('Projection "%s" had no state row to retire; its derived stream "%s" stands as it was.', $name, $subject));
        } else {
            // unreachable while the guard admits these two kinds and no other; loud rather than a
            // fallback, since a fallback is what let a widened guard reach a render that had not moved
            throw new LogicException(sprintf(
                'Projection "%s" was retired as a stream producer yet is neither a LinkProjection nor a FanOutLinkProjection: the retire guard and this render disagree on what a producer is.',
                $name,
            ));
        }

        $consumers = array_keys(array_filter(
            $this->registry->all(),
            static fn ($p): bool => $p instanceof DerivedStreamProjection && $consumes($p->sourceStream()->toString()),
        ));

        if ($consumers === []) {
            $io->warning(sprintf(
                'No registered projection consumes "%s" — did you mean delete? (ad-hoc inspect / replay / external readers are not detected.)',
                $subject,
            ));
        } else {
            implode(', ', $consumers)
                |> (static fn ($x) => sprintf('Kept for consumer(s): %s.', $x))
                |> $io->writeln(...);
        }

        return Command::SUCCESS;
    }
}
