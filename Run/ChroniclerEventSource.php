<?php

declare(strict_types=1);

namespace Storm\Projector\Run;

use Doctrine\DBAL\Exception;
use Storm\Chronicler\Query\ProjectionFilter;
use Storm\Chronicler\SafeHead\SafeHeadAdvancer;
use Storm\Chronicler\Store\StreamReader;
use Storm\EventLinks\DerivedStreamHead;
use Storm\EventLinks\DerivedStreamProjectionFilter;
use Storm\Stream\StreamName;

/**
 * The Chronicler-backed event source: the one place that knows both the Projector read window and
 * the store's DBAL filters. Deliberately PostgreSQL-shaped, never a storage abstraction.
 */
final readonly class ChroniclerEventSource implements ProjectionEventSource
{
    public function __construct(
        private StreamReader $streamReader,
        /**
         * The derived-consumer frontier. REQUIRED, never optional: a null would skip the cap silently,
         * so a manually wired runner would carry the whole consumer-outruns-producer data loss without a
         * word. Optional is right for an optimization, never for a correctness frontier; contrast the
         * advancer just below.
         */
        private DerivedStreamHead $derivedStreamHead,
        /**
         * Autowired in prod; null falls back to the pure read, the same watermark with no floor ratchet,
         * fine for a one-off or manually-wired runner where the scan tail stays small anyway. Optional
         * because it only makes an already-correct watermark cheaper.
         */
        private ?SafeHeadAdvancer $safeHeadAdvancer = null,
    ) {}

    /**
     * {@inheritDoc}
     *
     * With an advancer, `SafeHeadAdvancer::tick()` lets the one daemon that wins the per-cycle
     * advisory lock scan the gap and ratchet the persisted floor; the rest read that floor in O(1)
     * with no scan, so only one daemon scans per cycle and N daemons neither serialize on the
     * singleton's tuple lock nor re-scan the same lagging tail. The floor is conservative, at most
     * the true safe head, so a loser bounds its read below any in-flight position and cannot skip;
     * it is at most one writer-cycle behind. The lock is transaction-scoped: un-split, the ratchet
     * rides the batch transaction and a deadlock-retry rollback undoes it and frees the lock;
     * under a read-model store split, where the batch transaction lives on the other connection,
     * the advancer opens its own short transaction so the election keeps working, and the ratchet
     * then survives a batch rollback, harmless since the floor is an optimization, never a
     * correctness input.
     *
     * @throws Exception on a DBAL failure of the safe-head scan or the link-head probe
     */
    public function watermark(?StreamName $sourceStream): int
    {
        $safeHead = $this->safeHeadAdvancer !== null
            ? $this->safeHeadAdvancer->tick()
            : $this->streamReader->safeHeadPosition();

        $max = $safeHead?->toOrdinal() ?? 0;

        if ($sourceStream !== null) {
            $max = min($max, $this->derivedStreamHead->headFor($sourceStream));
        }

        return $max;
    }

    /**
     * {@inheritDoc}
     *
     * A derived window folds its target stream by joining the link bookkeeping; a plain window is
     * the category and stored-type read. Materialized ON PURPOSE, not a leftover. First, `Checkpoint`
     * needs the record count to decide batch-full, advancing to the last applied, versus exhausted,
     * advancing to the scan max, the empty-advance contract. Second, it closes the result set BEFORE
     * `apply()` writes on the SAME connection inside the batch tx; driver-safe by construction,
     * pdo_pgsql buffers the full result anyway, so a generator would defer hydration only, and memory
     * is bounded by the window's limit, THE knob.
     *
     * @throws Exception on a DBAL failure of the batch read
     */
    public function read(ProjectionReadWindow $window): array
    {
        $filter = $window->sourceStream !== null
            ? new DerivedStreamProjectionFilter($window->after, $window->max, $window->limit, $window->sourceStream)
            : new ProjectionFilter($window->after, $window->max, $window->limit, $window->categories, $window->types);

        return iterator_to_array($this->streamReader->retrieveByFilter($filter), false);
    }
}
