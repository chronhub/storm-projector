<?php

declare(strict_types=1);

namespace Storm\Projector\Query;

/**
 * The run mode of a checkpoint-less query fold, the query lane's pendant of the persistent runner's
 * once/drain/daemon, minus lease and checkpoint.
 *
 * @see QueryRunOptions
 * @see QueryProjectionRunner::stream()
 */
enum QueryRunMode
{
    /** Fold a single page of `batch` events from `from`, then stop; a bounded peek. */
    case Once;

    /** Fold from `from` up to the safe-head snapshot, paged, then stop; the current answer. */
    case Drain;

    /** Drain, then keep polling: fold each new `(cursor, safeHead]` window as events arrive. */
    case Daemon;
}
