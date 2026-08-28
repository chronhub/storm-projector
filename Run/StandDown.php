<?php

declare(strict_types=1);

namespace Storm\Projector\Run;

/**
 * Why a run attempt did not begin, the three ways a projection can decline to start.
 *
 * They look identical from outside and call for opposite gestures, which is the whole reason they
 * are named: one is an operator's own hold waiting for their verb, one is another worker doing the
 * job correctly, and one is a race an operator won without knowing it.
 */
enum StandDown: string
{
    /**
     * The stored status is not runnable, so a paused, stopping or failed projection is waiting for
     * `mark:resume`, a clean stop, or `retry` / `reset`. The operator's own hold, and the only case
     * where a verb of theirs is the way out.
     */
    case NotRunnable = 'not_runnable';

    /**
     * Another worker holds the lease. Nothing is wrong and nothing needs doing: the projection IS
     * being caught up, by somebody else.
     */
    case LeaseHeld = 'lease_held';

    /**
     * A `mark:pause` or `mark:stop` landed between the claim and the flip to running, and won. The
     * mark is honored rather than overwritten, so this is the race an operator won: their verb took
     * effect, and the worker they raced stood down for it.
     */
    case MarkedMidClaim = 'marked_mid_claim';
}
