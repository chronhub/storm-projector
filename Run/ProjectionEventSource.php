<?php

declare(strict_types=1);

namespace Storm\Projector\Run;

use Storm\Chronicler\Record\EventRecord;
use Storm\Projector\Run\Stage\ReadBatch;
use Storm\Stream\StreamName;

/**
 * The event source a projection run reads from: the frontier it may advance to, and one bounded
 * page of records under that frontier. The seam between the batch pipeline and the store: the
 * stage owns the run's rules, the `--to` cap, the short-circuit, where the page lands; the source
 * owns how a frontier is probed and how a selection becomes a store query.
 *
 * @see ReadBatch
 * @see ChroniclerEventSource
 */
interface ProjectionEventSource
{
    /**
     * The highest position the given selection may advance to this cycle, in `sequence_no` space;
     * 0 when nothing is safely readable.
     *
     * For a plain selection this is the store's safe head, the frontier below which no in-flight
     * append can still commit. A derived selection is capped further, by its producer's LINK head:
     * the safe head advances over source positions the producer has not yet linked, and advancing
     * a checkpoint over them would lose every late link; behind the link head, unlinked positions
     * fold as a tail, ahead of it is undecided, so wait, never skip.
     */
    public function watermark(?StreamName $sourceStream): int;

    /**
     * One page of records inside the window, in `sequence_no` order.
     *
     * @return list<EventRecord>
     */
    public function read(ProjectionReadWindow $window): array;
}
