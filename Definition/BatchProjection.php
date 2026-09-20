<?php

declare(strict_types=1);

namespace Storm\Projector\Definition;

use Doctrine\DBAL\Connection;
use Storm\Chronicler\Record\EventRecord;
use Throwable;

/**
 * The batch verb, opt-in beside `Projection::apply()`: a projection implementing it hears a batch
 * once, every record of it in `sequence_no` order, instead of one `apply()` per record. The runner's
 * `ApplyBatch` stage prefers it when present; a projection without it keeps the per-record path, so
 * nothing existing changes. The verb exists for the writes a batch can send as one statement, the
 * links of a `LinkProjection` first, and it reports what `apply()` reports, the count of records
 * actually applied, so the batch telemetry and the checkpoint's `applied` stay what they were.
 *
 * The contract of `apply()` holds whole: every write rides `$tx`, the batch connection, and any
 * failure propagates, the runner rolling the batch back and marking the projection failed.
 *
 * @see Projection::apply()
 */
interface BatchProjection extends Projection
{
    /**
     * Apply a whole batch within the batch transaction, in the order given.
     *
     * @param  list<EventRecord>  $events  the batch, in `sequence_no` order, never empty
     * @return int the number of events applied, what a per-record `apply()` would have counted true
     *
     * @throws Throwable any handler failure, propagated to the runner as `apply()`'s are
     */
    public function applyBatch(array $events, Connection $tx): int;
}
