<?php

declare(strict_types=1);

namespace Storm\Projector\Freshness;

use Storm\Chronicler\Exception\InvalidPosition;
use Storm\Chronicler\Exception\StorageFailure;
use Storm\Chronicler\Store\StreamReader;
use Storm\Contracts\Chronicler\Position;
use Storm\Contracts\Projector\ProjectionFreshness;
use Storm\EventLinks\DerivedStreamHead;
use Storm\EventLinks\DerivedStreamRevision;
use Storm\Projector\Definition\FanOutLinkProjection;
use Storm\Projector\Definition\LinkProjection;
use Storm\Projector\Registry\ProjectionRegistry;
use Storm\Projector\Store\ProjectionCatalog;
use Storm\Projector\Store\ProjectionRow;
use Storm\Stream\StreamName;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Throwable;

/**
 * The DBAL concrete of the contracted {@see \Storm\Contracts\Projector\ProjectionFreshness} port: the
 * checkpoint is polled through `ProjectionStore::findRow()`, the head through
 * `StreamReader::safeHeadPosition()`.
 *
 * WHICH head a projection is measured against depends on its kind, resolved by `headFor()`: a derived
 * consumer's freshness is its PRODUCER's link head, honest only once the producer has certified the
 * tail, never the global safe head.
 *
 * A consuming boundary, so every failure of the underlying reads is wrapped into the contracted
 * `StorageFailure` here, cause preserved; no raw driver or decode type crosses the port.
 */
#[AsAlias(ProjectionFreshness::class)]
final readonly class ProjectionWaiter implements ProjectionFreshness
{
    public function __construct(
        private ProjectionCatalog $store,
        private StreamReader $streamReader,
        /**
         * Resolves a derived consumer's PRODUCER: `headFor()` looks it up here and reads its checkpoint
         * before trusting the cap. The routed store injected above lands the producer's row read on its
         * own home, the events side, without this class learning that homes exist.
         */
        private ProjectionRegistry $registry,
        /**
         * `ReadBatch` caps a derived consumer's scan at its producer's link head, so measuring it
         * against the global safe head reports lag it can never work off.
         */
        private DerivedStreamHead $derivedStreamHead,
        /**
         * A derived checkpoint is valid only for the membership revision it was built against. A
         * position can stay unchanged across a rebuild, so the head alone cannot certify freshness.
         */
        private DerivedStreamRevision $derivedStreamRevision,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws InvalidPosition when the token's string form is not the contracted decimal ordinal
     */
    public function isCaughtUp(string $name, Position $token): bool
    {
        $row = $this->guard('projection checkpoint read', fn () => $this->store->findRow($name));

        return $row !== null
            && $this->sourceRevisionIsCurrent($row)
            && $row->lastPosition >= $this->ordinal($token);
    }

    /**
     * {@inheritDoc}
     *
     * @throws InvalidPosition when the token's string form is not the contracted decimal ordinal
     */
    public function waitFor(
        string $name,
        Position $token,
        float $timeoutSeconds = 5.0,
        // @infection-ignore-all; as on waitForHead(), a one-millisecond change to the polling
        // default is a scheduler effect, not a deterministic semantic distinction
        int $pollMs = 50,
    ): bool {
        return $this->pollUntil(fn (): bool => $this->isCaughtUp($name, $token), $timeoutSeconds, $pollMs);
    }

    /**
     * {@inheritDoc}
     *
     * The at-head SIGNAL only; the blue/green swap machinery, the shadow table, the atomic swap and the
     * dependent cascade, is not built here.
     *
     * Built on `safeHeadPosition()`, which holds below the first young in-flight `sequence_no` gap via
     * `SafeHeadAdvancer`, so "at head" does not silently mask the high-concurrency skip. The one residual
     * is the grace window: a transaction in-flight past it is presumed aborted, so this still cannot
     * certify completeness against that pathological case.
     *
     * @throws InvalidPosition when the head's string form is not the contracted decimal ordinal
     */
    public function isAtHead(string $name): bool
    {
        $row = $this->guard('projection checkpoint read', fn () => $this->store->findRow($name));

        if ($row !== null && ! $this->sourceRevisionIsCurrent($row)) {
            return false;
        }

        $head = $this->headFor($row);

        // A head of 0 is an empty store or a producer that certified an empty derived stream, both of
        // them genuinely at head; `headFor` owes that guarantee, since a 0 meaning "nothing measurable"
        // would be read right here as "nothing to catch up on". Otherwise a MISSING row is a projection
        // that never ran: behind by everything, never "fresh"; answering true there would hand a
        // blue/green swap the green light for a read model that does not exist yet.
        return $head === 0 || ($row !== null && $row->lastPosition >= $head);
    }

    /**
     * {@inheritDoc}
     *
     * The trade for the token-less wait: it rides the projection's WHOLE lag, not just the caller's own
     * write.
     *
     * @throws InvalidPosition when the head's string form is not the contracted decimal ordinal
     */
    public function waitForHead(
        string $name,
        float $timeoutSeconds = 5.0,
        // @infection-ignore-all; a one-millisecond change to the public polling default is only a
        // wall-clock scheduling effect, not a deterministic semantic distinction a unit can hold
        int $pollMs = 50,
    ): bool {
        // Freeze the GLOBAL safe head, the read-your-writes boundary as of the request. A filtered
        // projection polls that frozen position directly, one row read per tick, the same cost as
        // the token wait this method reads as a sibling of. A derived consumer cannot ride the
        // freeze: while its producer is behind, the global head is only a conservative provisional
        // answer, and once the producer certifies the frozen tail the reachable target may collapse
        // to a lower link head. The derived path therefore pays up to four reads per tick, consumer
        // row, revision, producer row and link head, up to a hundred times on the HTTP request
        // path, where the frozen path pays one; the cost is accepted, since the one-read economy
        // was exactly what let a rebuilt derived set answer green.
        $row = $this->guard('projection checkpoint read', fn () => $this->store->findRow($name));
        $head = $this->safeHead();

        if ($head === 0) {
            return $row === null || $this->sourceRevisionIsCurrent($row); // an empty store is trivially at head unless a derived set is stale
        }

        if ($row?->sourceStream !== null) {
            return $this->waitForDerivedHead($name, $row, $head, $timeoutSeconds, $pollMs);
        }

        return $this->pollUntil(function () use ($name, $head): bool {
            $row = $this->guard('projection checkpoint read', fn () => $this->store->findRow($name));

            return $row !== null && $row->lastPosition >= $head;
        }, $timeoutSeconds, $pollMs);
    }

    /**
     * {@inheritDoc}
     *
     * A projection that never ran is behind by the WHOLE head, not caught up; 0 comes back only from a
     * head of 0.
     *
     * @throws InvalidPosition when the head's string form is not the contracted decimal ordinal
     */
    public function lag(string $name): int
    {
        $row = $this->guard('projection checkpoint read', fn () => $this->store->findRow($name));
        $head = $this->headFor($row);
        $lag = max(0, $head - ($row === null ? 0 : $row->lastPosition));

        // Revision drift is set lag rather than positional lag, but zero is the public green signal.
        // Keep it non-zero until reset/replay makes the checkpoint meaningful again.
        return $row !== null && ! $this->sourceRevisionIsCurrent($row) ? max(1, $lag) : $lag;
    }

    /**
     * The head THIS projection can actually reach, in `sequence_no` space.
     *
     * For a filtered projection that is the event-store safe head. For a DERIVED consumer, freshness is
     * measured against its PRODUCER, and the answer splits three ways on the producer's own state:
     *
     * - A REGISTERED producer that is absent or behind, no `projections` row or `lastPosition` below the
     *   safe head: every position past its checkpoint is undecided, it may still link there, so the link
     *   head proves nothing and the consumer is NOT at head. The global safe head is returned, an honest
     *   overestimate in the safe direction; a producer registered but never launched reads as a link
     *   head of 0, and capping on it would answer at-head and lag-0 over an EMPTY read model, the exact
     *   false blue/green and HTTP-freshness signal the derived cap exists to kill;
     *
     * - A REGISTERED producer at head, `lastPosition >= safeHead`: the tail is certified, so the cap is
     *   exactly `min(safe head, link head)`, as `ReadBatch` caps the scan, and a consumer that folded
     *   every existing link is caught up whatever the rest of the store has been doing;
     *
     * - NO registered producer, a deliberately retired or externally produced stream: the link head is
     *   all there is and all there will be, so the cap stands; measuring against the global head would
     *   report lag the consumer can never work off, permanently, and `isAtHead()` never true.
     *
     * @throws StorageFailure when the safe-head, producer-row or link-head read fails at the storage level
     * @throws InvalidPosition when the head's string form is not the contracted decimal ordinal
     */
    private function headFor(?ProjectionRow $row): int
    {
        $head = $this->safeHead();

        if ($row?->sourceStream === null) {
            return $head; // a filtered projection, or no row at all: nothing to cap it with
        }

        $producer = $this->producerNameOf($row->sourceStream);

        if ($producer !== null) {
            $producerRow = $this->guard('producer checkpoint read', fn (): ?ProjectionRow => $this->store->findRow($producer));

            if ($producerRow === null || $producerRow->lastPosition < $head) {
                return $head; // producer never launched or behind: the tail is undecided, the cap proves nothing
            }
        }

        $linkHead = $this->guard(
            'derived stream head read',
            fn (): int => $this->derivedStreamHead->headFor($row->sourceStream),
        );

        if ($producer === null && $linkHead === 0) {
            // No producer to ask and no link to measure: the cap would be 0, and a head of 0 reads as an
            // EMPTY STORE one caller up, so absence of evidence would become a green light. This is
            // branch 1's position exactly, nothing proves the tail is finished, so it takes branch 1's
            // answer. A producer that certified an empty stream still caps at 0 above, and a retired
            // stream that DID link keeps its own head: only the case with no evidence at all moves.
            return $head;
        }

        return min($head, $linkHead);
    }

    /**
     * Wait against the request's frozen global boundary while allowing a registered producer to
     * certify that its actual derived head is lower. The revision and consumer row stay live: a reset
     * and replay during the wait may repair them, while a rebuilt-but-unreset consumer never goes green.
     *
     * @param  positive-int  $pollMs
     */
    private function waitForDerivedHead(
        string $name,
        ProjectionRow $initial,
        int $safeHead,
        float $timeoutSeconds,
        int $pollMs,
    ): bool {
        $source = $initial->sourceStream;
        if ($source === null) {
            return false; // narrowed by the caller; defensive against a future call-site change
        }

        $sourceName = $source->toString();
        $producer = $this->producerNameOf($source);

        return $this->pollUntil(function () use ($name, $source, $sourceName, $producer, $safeHead): bool {
            $consumer = $this->guard('projection checkpoint read', fn () => $this->store->findRow($name));
            if ($consumer === null
                || $consumer->sourceStream?->toString() !== $sourceName
                || ! $this->sourceRevisionIsCurrent($consumer)) {
                return false;
            }

            if ($producer !== null) {
                $producerRow = $this->guard('producer checkpoint read', fn (): ?ProjectionRow => $this->store->findRow($producer));
                if ($producerRow === null || $producerRow->lastPosition < $safeHead) {
                    return false; // the request-time tail is still undecided
                }
            }

            $linkHead = $this->guard('derived stream head read', fn (): int => $this->derivedStreamHead->headFor($source));
            if ($producer === null && $linkHead === 0) {
                return $consumer->lastPosition >= $safeHead; // no producer and no link: no evidence for a lower cap
            }

            return $consumer->lastPosition >= min($safeHead, $linkHead);
        }, $timeoutSeconds, $pollMs);
    }

    private function sourceRevisionIsCurrent(ProjectionRow $row): bool
    {
        if ($row->sourceStream === null) {
            return true;
        }

        $revision = $this->guard(
            'derived stream revision read',
            fn (): int => $this->derivedStreamRevision->revisionFor($row->sourceStream),
        );

        return $row->sourceRevision === $revision;
    }

    private function safeHead(): int
    {
        $safeHead = $this->guard('safe head read', fn () => $this->streamReader->safeHeadPosition());

        return $safeHead === null ? 0 : $this->ordinal($safeHead);
    }

    /**
     * The registered producer whose output namespace covers `$source`: a `LinkProjection` with that exact
     * `targetStream()`, or a `FanOutLinkProjection` whose `targetPrefix()` prefixes it. At most one can
     * match, the registry refuses duplicate targets and overlapping prefixes at construction. Null means
     * no registered producer covers the stream, retired or externally produced.
     */
    private function producerNameOf(StreamName $source): ?string
    {
        $stream = $source->toString();

        foreach ($this->registry->all() as $name => $projection) {
            if ($projection instanceof LinkProjection && $projection->targetStream()->toString() === $stream) {
                return $name;
            }

            if ($projection instanceof FanOutLinkProjection && str_starts_with($stream, $projection->targetPrefix())) {
                return $name;
            }
        }

        return null;
    }

    /**
     * The checkpoint boundary's guarded read: the `Position` contract pins `toOrdinal()` to the
     * positive int64 store ordinal; a foreign implementation that strays from it is refused loud
     * here, never silently compared, which would report a lagging projection as fresh.
     *
     * @throws InvalidPosition when the position's ordinal is not a positive integer
     */
    private function ordinal(Position $position): int
    {
        $ordinal = $position->toOrdinal();
        // @phpstan-ignore smaller.alwaysFalse (phpstan deems this dead under the very contract it defends against a foreign implementation)
        if ($ordinal < 1) {
            throw InvalidPosition::notAPositiveInteger((string) $ordinal);
        }

        return $ordinal;
    }

    /**
     * @param  callable(): bool  $probe
     * @param  positive-int  $pollMs
     */
    private function pollUntil(callable $probe, float $timeoutSeconds, int $pollMs): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (true) {
            if ($probe()) {
                return true;
            }

            // @infection-ignore-all; equality between two independent microtime() samples is not a
            // reproducible boundary. The opposite comparison is exercised by the multi-probe test.
            $expired = microtime(true) >= $deadline;

            if ($expired) {
                return false;
            }

            // @infection-ignore-all; the exact scheduler delay or its absence has no deterministic
            // observer here. The probe sequence, deadline and final verdict are the tested contract.
            usleep($pollMs * 1000);
        }
    }

    /**
     * The consuming-boundary wrap: everything the storage read raises becomes the contracted
     * `StorageFailure`, cause preserved, a malformed stored row included. Nothing crosses this port
     * raw. The caller of a freshness question can repair neither a lost connection nor a corrupt
     * topology row, so the two are one answer to it: the probe cannot say.
     *
     * @template T
     *
     * @param  callable(): T  $read
     * @return T
     *
     * @throws StorageFailure
     */
    private function guard(string $operation, callable $read): mixed
    {
        try {
            return $read();
        } catch (Throwable $e) {
            throw StorageFailure::wrap($operation, $e);
        }
    }
}
