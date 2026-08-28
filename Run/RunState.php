<?php

declare(strict_types=1);

namespace Storm\Projector\Run;

use Storm\Chronicler\Record\EventRecord;

/**
 * Mutable, typed state threaded through the stages of one run. The immutable config sits on
 * `$profile`.
 */
final class RunState
{
    /** The checkpoint: last applied global position, 0 when fresh. */
    public int $lastPosition = 0;

    /** The safe-head ceiling read this batch; the advance target when the batch is not full. */
    public int $scanMax = 0;

    /** The real safe head this cycle, uncapped by `--to`, for the lag metric `scanHead - lastPosition`. */
    public int $scanHead = 0;

    /** Wall-clock of this cycle's safe-head probe, in ms; surfaces the watermark cost. */
    public float $watermarkMs = 0.0;

    /** @var list<EventRecord> the current batch */
    public array $records = [];

    /** Events successfully applied this batch, where `apply()` returned true. */
    public int $applied = 0;

    /** Position of the last record applied this batch. */
    public int $lastApplied = 0;

    /** Current idle backoff for the daemon, in ms. */
    public int $idleMs;

    public function __construct(
        public readonly RunProfile $profile,
    ) {
        $this->idleMs = $profile->options->backoffMin;
    }

    /**
     * Reset the per-batch fields, called before each transaction attempt so a deadlock retry re-reads
     * cleanly with no double counting.
     */
    public function resetBatch(): void
    {
        // lastPosition is deliberately NOT reset: it is the checkpoint, re-established by the
        // acquire stage at the next attempt, not a per-batch scratch value
        $this->records = [];
        $this->applied = 0;
        $this->lastApplied = 0;
        $this->scanMax = 0;
        $this->scanHead = 0;
        $this->watermarkMs = 0.0;
    }
}
