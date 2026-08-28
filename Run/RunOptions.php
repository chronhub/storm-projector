<?php

declare(strict_types=1);

namespace Storm\Projector\Run;

use Storm\Projector\Exception\InvalidRunOptions;

/**
 * Run-time knobs for one `ProjectionRunner::run()`. Immutable, one flat surface: the safe-head watermark
 * removed the near-head tuning a separate options object would have carried, so `backoff*` is the only
 * knob. The idle delay grows from `backoffMin` to `backoffMax` by `backoffStep` each idle cycle, validated
 * `min > 0`, `max >= min`, `step >= 0`; daemon signals come with the console command.
 *
 * Mutually exclusive modes, validated:
 * - Once, no flag: one batch, then stop.
 *
 * - Drain: loop until caught up to the safe head, then stop.
 *
 * - Daemon: loop forever with idle backoff and lease renewal.
 *
 * - To, via `$to`: run up to a fixed position bounded by the safe head, then stop; a snapshot or bounded
 *   catch-up, idempotent on a re-run to the same target.
 *
 * `$owner` is the leaseholder identity, and it conditions coordination: the lease is re-entrant for the
 * same owner, since `claimLease` matches `lease_owner = :owner`, so the same owner continues its own run
 * while a different owner contends. It MUST therefore be distinct per concurrent worker; a shared owner
 * lets two runs process the same projection, with no single-writer. The console defaults it to
 * `hostname:pid`; pass it explicitly only for a stable identity, e.g. a restart re-taking its lease.
 *
 * @see ProjectionRunner::run()
 */
final readonly class RunOptions
{
    /**
     * @param  positive-int  $batch  events per batch; still validated `> 0` at construction, a runtime
     *                               invariant so every caller is guarded, not only the phpstan-checked
     *                               path
     * @param  int  $leaseTtl  seconds; validated `> 0`. MUST exceed the worst-case single-batch wall time,
     *                         a read plus N applies plus checkpoint in one tx; a batch slower than this
     *                         loses the lease to a rival mid-run and re-processes that batch
     * @param  int|null  $to  validated `> 0` when set. Advance the checkpoint through N, scanning, not
     *                        necessarily applying the event at N: a non-full final batch lands the checkpoint
     *                        at `min(safeHead, to)` even past the last matching event, so "checkpoint = N"
     *                        means "scanned through N", which snapshot tooling must reason about. Bounded by
     *                        the safe head; idempotent on a re-run to the same N
     *
     * @throws InvalidRunOptions on an out-of-range value, a non-positive batch, leaseTtl, to or backoffMin,
     *                           a negative backoffStep or maxRuntime, or backoffMax below backoffMin; on an
     *                           empty owner; or on an incoherent mode combination
     */
    public function __construct(
        public string $owner,
        public int $batch = 1000,
        public bool $daemon = false,
        public bool $drain = false,
        public bool $allowStale = false,
        public int $leaseTtl = 60,
        public int $backoffMin = 200,
        public int $backoffMax = 1000,
        public int $backoffStep = 100,
        public ?int $maxRuntime = null,
        public ?int $to = null,
    ) {
        if ($owner === '') {
            throw InvalidRunOptions::emptyOwner();
        }

        // phpstan deems this dead under the very phpdoc type it defends; the runtime check is the guard
        // @phpstan-ignore smallerOrEqual.alwaysFalse
        if ($batch <= 0) {
            throw InvalidRunOptions::notPositive('batch', $batch);
        }

        if ($leaseTtl <= 0) {
            throw InvalidRunOptions::notPositive('leaseTtl', $leaseTtl);
        }

        if ($to !== null && $to <= 0) {
            throw InvalidRunOptions::notPositive('to', $to);
        }

        if ($backoffMin <= 0) {
            throw InvalidRunOptions::notPositive('backoffMin', $backoffMin);
        }

        if ($backoffStep < 0) {
            throw InvalidRunOptions::mustNotBeNegative('backoffStep', $backoffStep);
        }

        if ($backoffMax < $backoffMin) {
            throw InvalidRunOptions::incoherentBackoffRange($backoffMin, $backoffMax);
        }

        if ($maxRuntime !== null && $maxRuntime < 0) {
            throw InvalidRunOptions::mustNotBeNegative('maxRuntime', $maxRuntime);
        }

        if ($daemon && $drain) {
            throw InvalidRunOptions::daemonWithDrain();
        }

        if ($to !== null && ($daemon || $drain)) {
            throw InvalidRunOptions::targetWithContinuousMode();
        }
    }
}
