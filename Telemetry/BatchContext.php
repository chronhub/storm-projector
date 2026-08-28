<?php

declare(strict_types=1);

namespace Storm\Projector\Telemetry;

/**
 * Carries the metrics-worthy data of a single projection batch, one transactional cycle through the
 * runner pipeline of read, apply and checkpoint. Built at the call site, consumed by whatever
 * ProjectorObservability implementation is wired.
 *
 * eventCount is the number of events the pipeline saw in this batch, the seen value returned by the inner
 * pipeline. A productive cycle has eventCount greater than 0; an idle cycle, caught up to the safe head,
 * has eventCount 0 and signals end-of-stream in once and drain modes.
 *
 * `eventCount` versus `applied` is the ops discriminant for the single-cursor's empty advance: a
 * checkpoint that jumps with `applied = 0` and eventCount 0 is the cursor traversing foreign traffic, not
 * work.
 *
 * @see ProjectorObservability
 */
final readonly class BatchContext
{
    public function __construct(
        /** Projection name, the registry key. */
        public string $projection,
        /** Events the pipeline SAW this batch, records fetched and matched by the filter, 0 when caught up. */
        public int $eventCount,
        /** Wall-clock duration of the batch transaction, in milliseconds. */
        public float $durationMs,
        /** Positions behind the safe head after this batch, 0 when caught up. A per-cycle lag metric. */
        public int $lag = 0,
        /** Wall-clock of this cycle's safe-head probe, in ms; couples to the oldest open tx. */
        public float $watermarkMs = 0.0,
        /** Events actually APPLIED this batch, where `apply()` returned true, seen minus the no-ops/duplicates. */
        public int $applied = 0,
    ) {}
}
