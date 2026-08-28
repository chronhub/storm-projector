<?php

declare(strict_types=1);

namespace Storm\Projector\Tests;

use JsonException;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Chronicler\Exception\InvalidPosition;
use Storm\Chronicler\Exception\StorageFailure;
use Storm\Chronicler\Record\SequencePosition;
use Storm\Chronicler\Store\StreamReader;
use Storm\Contracts\Chronicler\Position;
use Storm\EventLinks\DerivedStreamHead;
use Storm\EventLinks\DerivedStreamRevision;
use Storm\Projector\Freshness\ProjectionWaiter;
use Storm\Projector\Registry\ProjectionRegistry;
use Storm\Projector\Store\ProjectionRow;
use Storm\Projector\Store\ProjectionStatus;
use Storm\Projector\Store\ProjectionStore;
use Storm\Projector\Tests\Fixture\StubFanOutProjection;
use Storm\Projector\Tests\Fixture\StubLinkProjection;
use Storm\Stream\StreamName;

/**
 * `guard()`'s wrap, in isolation: a genuine JsonException from the injected ProjectionStore, a real
 * port substituted here exactly as DI would, must reach the caller as the contracted StorageFailure
 * with the decode failure kept as its cause. The same wrap is proven against a real Postgres failure
 * in {@see \Storm\Tests\Integration\Projector\ProjectionWaiterTest}; the decode path is proven here at
 * the unit level instead, since no real Postgres row can produce it: `categories`/`event_classes` are
 * `jsonb` columns and Postgres itself refuses to store syntactically invalid JSON in one.
 *
 * Also `headFor()`'s producer split, against stubbed rows: a derived consumer's freshness is measured
 * against its PRODUCER, so the link-head cap only applies once the registered producer certified the
 * tail; a producer never launched or behind means not-at-head with the honest global distance, and only
 * a stream with NO registered producer, retired or external, keeps the bare link-head cap.
 */
final class ProjectionWaiterTest extends TestCase
{
    #[Test]
    public function a_malformed_row_crosses_the_port_as_a_storage_failure_keeping_its_cause(): void
    {
        // The exact instance a real malformed decode would raise, not a lookalike constructed by hand,
        // so the cause proves guard() wrapped THIS failure rather than rebuilding a lookalike.
        $malformed = $this->jsonExceptionFromARealDecodeFailure();

        $store = $this->createStub(ProjectionStore::class);
        $store->method('findRow')->willThrowException($malformed);

        $waiter = new ProjectionWaiter($store, $this->createStub(StreamReader::class), new ProjectionRegistry, $this->createStub(DerivedStreamHead::class), $this->createStub(DerivedStreamRevision::class));

        try {
            $waiter->isCaughtUp('account_balance', $this->createStub(Position::class));
            self::fail('expected the malformed row to surface a StorageFailure');
        } catch (StorageFailure $e) {
            // the decode failure is infrastructural to whoever asks a freshness question: it can repair
            // neither a lost connection nor a corrupt topology row, so both are one answer. Raw types
            // stop here, and the original survives as the cause for the operator who CAN repair it.
            self::assertSame($malformed, $e->getPrevious());
        }
    }

    #[Test]
    public function the_first_ordinal_passes_the_boundary_guard(): void
    {
        // the floor of the guard, pinned: ordinal 1 is the first legal position every stream opens
        // on, and an off-by-one refusal would reject every wait against a one-event store
        $store = $this->createStub(ProjectionStore::class);
        $store->method('findRow')->willReturn($this->rowAtPosition(42));

        $waiter = new ProjectionWaiter($store, $this->createStub(StreamReader::class), new ProjectionRegistry, $this->createStub(DerivedStreamHead::class), $this->createStub(DerivedStreamRevision::class));

        self::assertTrue($waiter->isCaughtUp('account_balance', SequencePosition::fromInt(1)));
    }

    #[Test]
    public function a_non_decimal_position_is_refused_loud_never_read_as_zero(): void
    {
        // the Position contract pins the canonical string form to the decimal int64 ordinal; a foreign
        // implementation that strays would be cast to 0, reporting a lagging projection as FRESH.
        // The checkpoint boundary validates the conversion.
        $store = $this->createStub(ProjectionStore::class);
        $store->method('findRow')->willReturn($this->rowAtPosition(42));

        $waiter = new ProjectionWaiter($store, $this->createStub(StreamReader::class), new ProjectionRegistry, $this->createStub(DerivedStreamHead::class), $this->createStub(DerivedStreamRevision::class));

        $this->expectException(InvalidPosition::class);

        $waiter->isCaughtUp('account_balance', new class() implements Position
        {
            public function isBefore(Position $other): bool
            {
                return false;
            }

            public function isAfter(Position $other): bool
            {
                return false;
            }

            public function equals(Position $other): bool
            {
                return false;
            }

            public function toString(): string
            {
                return 'v2:not-a-number';
            }

            public function toOrdinal(): int
            {
                // @phpstan-ignore return.type (the violation is the point: a foreign implementation lying about the contracted positive ordinal must be refused loud)
                return 0;
            }

            public function __toString(): string
            {
                return $this->toString();
            }
        });
    }

    #[Test]
    public function a_consumer_of_a_registered_never_launched_producer_is_behind_by_everything(): void
    {
        // The producer is REGISTERED but has no `projections` row: it never ran, so nothing is linked and
        // the link head reads 0. Without the producer check, min(safeHead, 0) = 0 made its consumer
        // answer at-head/lag-0 over an EMPTY read model, the false blue/green and HTTP-freshness signal.
        // The honest answer is the global distance: the producer may still link anywhere below the head.
        $waiter = $this->waiter(
            registry: new ProjectionRegistry([new StubLinkProjection('stub_link', 'stub-link-target')]),
            rows: ['feed_consumer' => $this->derivedRow('feed_consumer', 0, 'stub-link-target')],
            safeHead: 7,
            linkHead: 0,
        );

        self::assertFalse($waiter->isAtHead('feed_consumer'));
        self::assertSame(7, $waiter->lag('feed_consumer'));
    }

    #[Test]
    public function the_producer_lookup_covers_a_fan_out_prefix_namespace(): void
    {
        // Same never-launched trap through the OTHER producer type: a fan-out declares no fixed target,
        // its outputs are computed under `targetPrefix()`, so the lookup must match by prefix or every
        // fan-out consumer silently falls back to the bare link-head cap and reads 0 as at-head.
        $waiter = $this->waiter(
            registry: new ProjectionRegistry([new StubFanOutProjection('stub_fan_out', 'stub-fan-')]),
            rows: ['fan_consumer' => $this->derivedRow('fan_consumer', 0, 'stub-fan-money')],
            safeHead: 7,
            linkHead: 0,
        );

        self::assertFalse($waiter->isAtHead('fan_consumer'));
        self::assertSame(7, $waiter->lag('fan_consumer'));
    }

    #[Test]
    public function a_consumer_is_not_at_head_while_its_registered_producer_is_behind(): void
    {
        // The consumer folded EVERY existing link, checkpoint = link head = 2, but its producer is at 4
        // of a safe head of 7, so positions 5..7 are undecided: it may still link there. The cap is only
        // honest once the producer certified the tail; until then the consumer reports the global
        // distance, an over-estimate in the safe direction, never a premature at-head.
        $waiter = $this->waiter(
            registry: new ProjectionRegistry([new StubLinkProjection('stub_link', 'stub-link-target')]),
            rows: [
                'feed_consumer' => $this->derivedRow('feed_consumer', 2, 'stub-link-target'),
                'stub_link' => $this->derivedRow('stub_link', 4, null, 'filtered'),
            ],
            safeHead: 7,
            linkHead: 2,
        );

        self::assertFalse($waiter->isAtHead('feed_consumer'));
        self::assertSame(5, $waiter->lag('feed_consumer'));
    }

    #[Test]
    #[Group('adversarial')]
    public function a_wait_accepts_the_lower_link_head_once_the_producer_certifies_the_frozen_tail(): void
    {
        $consumer = $this->derivedRow('feed_consumer', 2, 'stub-link-target');
        $producerReads = 0;

        $store = $this->createStub(ProjectionStore::class);
        $store->method('findRow')->willReturnCallback(function (string $name) use ($consumer, &$producerReads): ?ProjectionRow {
            if ($name === 'feed_consumer') {
                return $consumer;
            }

            if ($name === 'stub_link') {
                return $this->derivedRow('stub_link', ++$producerReads === 1 ? 4 : 7, null, 'filtered');
            }

            return null;
        });

        $streamReader = $this->createStub(StreamReader::class);
        $streamReader->method('safeHeadPosition')->willReturn(SequencePosition::fromInt(7));
        $linkHead = $this->createStub(DerivedStreamHead::class);
        $linkHead->method('headFor')->willReturn(2);

        $waiter = new ProjectionWaiter(
            $store,
            $streamReader,
            new ProjectionRegistry([new StubLinkProjection('stub_link', 'stub-link-target')]),
            $linkHead,
            $this->createStub(DerivedStreamRevision::class),
        );

        self::assertTrue($waiter->waitForHead('feed_consumer', timeoutSeconds: 0.05, pollMs: 1));
        self::assertSame(2, $producerReads, 'the wait observed the producer certify the request-time tail');
    }

    #[Test]
    #[Group('adversarial')]
    public function a_rebuilt_source_never_reports_its_old_consumer_as_fresh(): void
    {
        $revision = $this->createStub(DerivedStreamRevision::class);
        $revision->method('revisionFor')->willReturn(1); // the consumer row below was built at revision 0

        $store = $this->createStub(ProjectionStore::class);
        $store->method('findRow')->willReturnCallback(fn (string $name): ?ProjectionRow => match ($name) {
            'feed_consumer' => $this->derivedRow('feed_consumer', 2, 'stub-link-target'),
            'stub_link' => $this->derivedRow('stub_link', 7, null, 'filtered'),
            default => null,
        });

        $streamReader = $this->createStub(StreamReader::class);
        $streamReader->method('safeHeadPosition')->willReturn(SequencePosition::fromInt(7));
        $linkHead = $this->createStub(DerivedStreamHead::class);
        $linkHead->method('headFor')->willReturn(2);

        $waiter = new ProjectionWaiter(
            $store,
            $streamReader,
            new ProjectionRegistry([new StubLinkProjection('stub_link', 'stub-link-target')]),
            $linkHead,
            $revision,
        );

        self::assertFalse($waiter->isAtHead('feed_consumer'));
        self::assertSame(1, $waiter->lag('feed_consumer'), 'revision drift must not reuse zero as the green signal');
        self::assertFalse($waiter->isCaughtUp('feed_consumer', SequencePosition::fromInt(2)));
        self::assertFalse($waiter->waitForHead('feed_consumer', timeoutSeconds: 0.0, pollMs: 1));
    }

    #[Test]
    public function an_empty_event_store_does_not_hide_a_stale_derived_revision(): void
    {
        $revision = $this->createStub(DerivedStreamRevision::class);
        $revision->method('revisionFor')->willReturn(1);

        $store = $this->createStub(ProjectionStore::class);
        $store->method('findRow')->willReturn($this->derivedRow('feed_consumer', 0, 'stub-link-target'));
        $streamReader = $this->createStub(StreamReader::class);
        $streamReader->method('safeHeadPosition')->willReturn(null);

        $waiter = new ProjectionWaiter(
            $store,
            $streamReader,
            new ProjectionRegistry,
            $this->createStub(DerivedStreamHead::class),
            $revision,
        );

        self::assertFalse($waiter->waitForHead('feed_consumer', timeoutSeconds: 0.0, pollMs: 1));
    }

    #[Test]
    public function a_source_topology_change_during_a_wait_cannot_satisfy_the_old_target(): void
    {
        $consumerReads = 0;
        $store = $this->createStub(ProjectionStore::class);
        $store->method('findRow')->willReturnCallback(function (string $name) use (&$consumerReads): ?ProjectionRow {
            if ($name === 'feed_consumer') {
                return ++$consumerReads === 1
                    ? $this->derivedRow('feed_consumer', 2, 'stub-link-target')
                    : $this->derivedRow('feed_consumer', 7, null, 'filtered');
            }

            return $name === 'stub_link' ? $this->derivedRow('stub_link', 7, null, 'filtered') : null;
        });

        $streamReader = $this->createStub(StreamReader::class);
        $streamReader->method('safeHeadPosition')->willReturn(SequencePosition::fromInt(7));
        $linkHead = $this->createStub(DerivedStreamHead::class);
        $linkHead->method('headFor')->willReturn(2);

        $waiter = new ProjectionWaiter(
            $store,
            $streamReader,
            new ProjectionRegistry([new StubLinkProjection('stub_link', 'stub-link-target')]),
            $linkHead,
            $this->createStub(DerivedStreamRevision::class),
        );

        self::assertFalse($waiter->waitForHead('feed_consumer', timeoutSeconds: 0.0, pollMs: 1));
    }

    #[Test]
    public function an_external_stream_with_no_links_accepts_a_checkpoint_exactly_at_the_conservative_head(): void
    {
        $waiter = $this->waiter(
            registry: new ProjectionRegistry,
            rows: ['feed_consumer' => $this->derivedRow('feed_consumer', 7, 'external-feed')],
            safeHead: 7,
            linkHead: 0,
        );

        self::assertTrue($waiter->waitForHead('feed_consumer', timeoutSeconds: 0.0, pollMs: 1));
    }

    #[Test]
    public function a_consumer_of_a_stream_with_no_registered_producer_keeps_the_link_head_cap(): void
    {
        // The retire case: the producer was deliberately decommissioned by `retire --orphan-stream`, or
        // the stream is externally produced. The link head is all there is and all there will be, so a
        // consumer that folded every link is at head; measuring against the global head would report
        // lag it can never work off, permanently.
        $waiter = $this->waiter(
            registry: new ProjectionRegistry,
            rows: ['feed_consumer' => $this->derivedRow('feed_consumer', 2, 'stub-link-target')],
            safeHead: 7,
            linkHead: 2,
        );

        self::assertTrue($waiter->isAtHead('feed_consumer'));
        self::assertSame(0, $waiter->lag('feed_consumer'));
    }

    #[Test]
    #[Group('adversarial')]
    public function no_producer_and_no_link_is_not_a_green_light(): void
    {
        // The same branch as above, at its ZERO. There is no producer to ask AND no link to measure, so
        // nothing distinguishes a stream that is finished from one that has not started: a typo in
        // `sourceStream()`, an external producer yet to write, a namespace emptied by forget. Capping at
        // 0 makes the head read as an EMPTY STORE one caller up, and absence of evidence hands a
        // blue/green swap its green light over a read model with nothing in it.
        $waiter = $this->waiter(
            registry: new ProjectionRegistry,
            rows: ['feed_consumer' => $this->derivedRow('feed_consumer', 0, 'stub-link-target')],
            safeHead: 7,
            linkHead: 0,
        );

        self::assertFalse($waiter->isAtHead('feed_consumer'));
        self::assertFalse($waiter->waitForHead('feed_consumer', timeoutSeconds: 0.01, pollMs: 1));
        self::assertSame(7, $waiter->lag('feed_consumer'), 'behind by everything, the honest overestimate');
    }

    #[Test]
    public function a_producer_that_certified_an_empty_stream_still_reads_at_head(): void
    {
        // the other side of the same zero, and the reason the fix is one condition and not a blanket
        // refusal: a REGISTERED producer caught up to the safe head has certified there was nothing to
        // link, so a consumer of that stream is genuinely at head over zero links
        $waiter = $this->waiter(
            registry: new ProjectionRegistry([new StubLinkProjection('stub_link', 'stub-link-target')]),
            rows: [
                'feed_consumer' => $this->derivedRow('feed_consumer', 0, 'stub-link-target'),
                'stub_link' => $this->derivedRow('stub_link', 7, null),
            ],
            safeHead: 7,
            linkHead: 0,
        );

        self::assertTrue($waiter->isAtHead('feed_consumer'));
        self::assertSame(0, $waiter->lag('feed_consumer'));
    }

    #[Test]
    public function caught_up_sits_exactly_on_the_token(): void
    {
        // the boundary is inclusive: a checkpoint AT the asked position has folded that position
        $waiter = $this->waiter(new ProjectionRegistry, ['rm' => $this->rowAtPosition(5)], safeHead: 9, linkHead: 0);

        self::assertTrue($waiter->isCaughtUp('rm', SequencePosition::fromInt(5)));
        self::assertFalse($waiter->isCaughtUp('rm', SequencePosition::fromInt(6)));
        self::assertFalse($waiter->isCaughtUp('missing', SequencePosition::fromInt(1)));
    }

    #[Test]
    public function an_absent_safe_head_reads_as_the_empty_store(): void
    {
        // no committed row anywhere: head 0, trivially at head even for a projection with no row,
        // and behind by nothing
        $waiter = $this->waiter(new ProjectionRegistry, [], safeHead: null, linkHead: 0);

        self::assertTrue($waiter->isAtHead('rm'));
        self::assertSame(0, $waiter->lag('rm'));
    }

    #[Test]
    public function waiting_for_an_empty_store_returns_immediately_even_without_a_projection_row(): void
    {
        $waiter = $this->waiter(new ProjectionRegistry, [], safeHead: null, linkHead: 0);

        self::assertTrue($waiter->waitForHead('rm', timeoutSeconds: 0.0, pollMs: 1));
    }

    #[Test]
    public function waiting_for_head_accepts_the_checkpoint_exactly_at_the_frozen_head(): void
    {
        $waiter = $this->waiter(new ProjectionRegistry, ['rm' => $this->rowAtPosition(5)], safeHead: 5, linkHead: 0);

        self::assertTrue($waiter->waitForHead('rm', timeoutSeconds: 0.0, pollMs: 1));
    }

    #[Test]
    public function waiting_for_head_keeps_a_missing_projection_row_behind(): void
    {
        $waiter = $this->waiter(new ProjectionRegistry, [], safeHead: 5, linkHead: 0);

        self::assertFalse($waiter->waitForHead('rm', timeoutSeconds: 0.0, pollMs: 1));
    }

    #[Test]
    public function waiting_for_a_certified_empty_fan_out_stream_uses_its_registered_producer(): void
    {
        $waiter = $this->waiter(
            registry: new ProjectionRegistry([new StubFanOutProjection('stub_fan_out', 'stub-fan-')]),
            rows: [
                'fan_consumer' => $this->derivedRow('fan_consumer', 0, 'stub-fan-money'),
                'stub_fan_out' => $this->derivedRow('stub_fan_out', 7, null, 'filtered'),
            ],
            safeHead: 7,
            linkHead: 0,
        );

        self::assertTrue($waiter->waitForHead('fan_consumer', timeoutSeconds: 0.0, pollMs: 1));
    }

    #[Test]
    public function a_wait_may_succeed_on_a_later_probe_before_its_deadline(): void
    {
        $reads = 0;
        $store = $this->createStub(ProjectionStore::class);
        $store->method('findRow')->willReturnCallback(function () use (&$reads): ?ProjectionRow {
            $reads++;

            return $reads === 1 ? null : $this->rowAtPosition(1);
        });

        $waiter = new ProjectionWaiter($store, $this->createStub(StreamReader::class), new ProjectionRegistry, $this->createStub(DerivedStreamHead::class), $this->createStub(DerivedStreamRevision::class));

        self::assertTrue($waiter->waitFor('rm', SequencePosition::fromInt(1), timeoutSeconds: 0.05, pollMs: 1));
        self::assertSame(2, $reads);
    }

    #[Test]
    public function a_missing_row_is_behind_by_the_whole_head(): void
    {
        // never ran: behind by everything, and the probe must survive the absent row
        $waiter = $this->waiter(new ProjectionRegistry, [], safeHead: 7, linkHead: 0);

        self::assertSame(7, $waiter->lag('rm'));
        self::assertFalse($waiter->isAtHead('rm'));
    }

    #[Test]
    public function lag_never_reports_a_negative_distance(): void
    {
        // a checkpoint past the safe head, a probe raced by the ratchet: caught up, never negative
        $waiter = $this->waiter(new ProjectionRegistry, ['rm' => $this->rowAtPosition(9)], safeHead: 7, linkHead: 0);

        self::assertSame(0, $waiter->lag('rm'));
        // and the exact-at-head twin stays zero as well
        self::assertSame(0, $this->waiter(new ProjectionRegistry, ['rm' => $this->rowAtPosition(7)], safeHead: 7, linkHead: 0)->lag('rm'));
    }

    #[Test]
    public function a_producer_exactly_at_head_certifies_the_tail(): void
    {
        // the cap turns honest the moment the producer's checkpoint REACHES the head, not one past
        // it: the consumer folded every link, so it is at head under the certified cap
        $waiter = $this->waiter(
            registry: new ProjectionRegistry([new StubLinkProjection('stub_link', 'stub-link-target')]),
            rows: [
                'feed_consumer' => $this->derivedRow('feed_consumer', 2, 'stub-link-target'),
                'stub_link' => $this->derivedRow('stub_link', 7, null, 'filtered'),
            ],
            safeHead: 7,
            linkHead: 2,
        );

        self::assertTrue($waiter->isAtHead('feed_consumer'));
        self::assertSame(0, $waiter->lag('feed_consumer'));
    }

    #[Test]
    public function a_fan_out_prefix_that_does_not_match_leaves_the_stream_external(): void
    {
        // BOTH conditions gate the fan-out arm: a registered fan-out whose prefix does not cover
        // the stream is not its producer, so the stream stays externally produced, link-head capped
        $waiter = $this->waiter(
            registry: new ProjectionRegistry([new StubFanOutProjection('stub_fan_out', 'stub-fan-')]),
            rows: ['feed_consumer' => $this->derivedRow('feed_consumer', 2, 'audit-1')],
            safeHead: 7,
            linkHead: 2,
        );

        self::assertTrue($waiter->isAtHead('feed_consumer'));
        self::assertSame(0, $waiter->lag('feed_consumer'));
    }

    /**
     * @param  array<string, ProjectionRow>  $rows  per-name stubbed `projections` rows; a missing name is null
     */
    private function waiter(ProjectionRegistry $registry, array $rows, ?int $safeHead, int $linkHead): ProjectionWaiter
    {
        $store = $this->createStub(ProjectionStore::class);
        $store->method('findRow')->willReturnCallback(static fn (string $name): ?ProjectionRow => $rows[$name] ?? null);

        $streamReader = $this->createStub(StreamReader::class);
        $streamReader->method('safeHeadPosition')->willReturn($safeHead === null ? null : SequencePosition::fromInt($safeHead));

        $derivedStreamHead = $this->createStub(DerivedStreamHead::class);
        $derivedStreamHead->method('headFor')->willReturn($linkHead);

        return new ProjectionWaiter($store, $streamReader, $registry, $derivedStreamHead, $this->createStub(DerivedStreamRevision::class));
    }

    private function derivedRow(string $name, int $lastPosition, ?string $sourceStream, string $mode = 'derived'): ProjectionRow
    {
        return new ProjectionRow(
            name: $name,
            status: ProjectionStatus::Idle,
            lastPosition: $lastPosition,
            mode: $mode,
            categories: [],
            eventClasses: [],
            sourceStream: $sourceStream === null ? null : new StreamName($sourceStream),
            sourceRevision: 0,
            targetStream: null,
            targetPrefix: null,
            leaseOwner: null,
            leaseUntil: null,
            lastHeartbeatAt: null,
            pauseUntil: null,
            generation: 1,
        );
    }

    private function rowAtPosition(int $lastPosition): ProjectionRow
    {
        return new ProjectionRow(
            name: 'account_balance',
            status: ProjectionStatus::Idle,
            lastPosition: $lastPosition,
            mode: 'read_model',
            categories: ['account'],
            eventClasses: [],
            sourceStream: null,
            sourceRevision: 0,
            targetStream: null,
            targetPrefix: null,
            leaseOwner: null,
            leaseUntil: null,
            lastHeartbeatAt: null,
            pauseUntil: null,
            generation: 0,
        );
    }

    private function jsonExceptionFromARealDecodeFailure(): JsonException
    {
        try {
            json_decode('{not valid json', true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return $e;
        }

        throw new LogicException('expected json_decode to throw on malformed input');
    }
}
