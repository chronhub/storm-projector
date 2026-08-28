<?php

declare(strict_types=1);

namespace Storm\Projector\Run;

use Storm\Projector\Store\ProjectionStatus;

/**
 * The decision `ProjectionRunner` acts on at each cycle boundary, computed from run values alone;
 * the boundary reads stay on the runner, the precedence over what they read lives here, pure and
 * provable by value, while the runner keeps the side effects each case names.
 */
enum CycleDecision
{
    /** End the loop; the runner releases the lease with the status its exit path chose. */
    case Stop;

    /** An operator's pause mark landed; stop and release keeping `Paused`, never reverting to `Idle`. */
    case StopKeepingPause;

    /** The consumed derived stream was rebuilt under the run; refuse the cycle rather than bury a link. */
    case RefuseRebuilt;

    /** Read again at once toward the `to` target; the target path never idles. */
    case Advance;

    /** The daemon caught up; idle before the next read, the backoff growing per `RunOptions`. */
    case AwaitEvents;

    /** A productive continuous cycle; read again with the idle backoff reset to its floor. */
    case Productive;

    /**
     * Takes the loop's decision for the cycle that just ran, in the boundary's precedence: an
     * operator's mark first, the rebuilt derived source next, then the stop request, then the
     * bounded target, and the run mode settles the rest.
     *
     * @param  ProjectionStatus|null  $status  the row status re-read at the boundary; `Stopping` and
     *                                         `Paused` are operator marks, a null row decides nothing
     * @param  bool  $sourceRebuilt  the consumed derived stream's revision drifted from the run's baseline
     * @param  bool  $stopRequested  a graceful-stop signal, or an exhausted runtime budget
     * @param  int  $seen  events seen by this cycle's batch
     * @param  int  $lastPosition  the checkpoint after this cycle's batch
     */
    public static function next(RunOptions $options, ?ProjectionStatus $status, bool $sourceRebuilt, bool $stopRequested, int $seen, int $lastPosition): self
    {
        if ($status === ProjectionStatus::Stopping) {
            return self::Stop; // released as Idle, same as any stop
        }

        if ($status === ProjectionStatus::Paused) {
            return self::StopKeepingPause;
        }

        if ($sourceRebuilt) {
            return self::RefuseRebuilt;
        }

        if ($stopRequested) {
            return self::Stop;
        }

        if ($options->to !== null) {
            // reached the target or caught up to the safe head below it
            return $lastPosition >= $options->to || $seen === 0 ? self::Stop : self::Advance;
        }

        if (! $options->daemon && ! $options->drain) {
            return self::Stop; // once: a single batch
        }

        if ($seen === 0) {
            return $options->drain ? self::Stop : self::AwaitEvents; // drain: caught up to the safe head
        }

        return self::Productive;
    }
}
