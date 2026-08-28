<?php

declare(strict_types=1);

namespace Storm\Projector\Tests;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Storm\Chronicler\Record\EventRecord;
use Storm\Chronicler\Record\SequencePosition;
use Storm\Clock\PointInTime;
use Storm\Message\Message;
use Storm\Projector\Exception\InvalidGroupKey;
use Storm\Projector\Tests\Fixture\RecordingGroupedReadModel;

final class AbstractGroupedReadModelTest extends TestCase
{
    #[Test]
    public function folds_the_event_once_per_key(): void
    {
        $projection = new RecordingGroupedReadModel(['debtor', 'creditor']);

        $applied = $projection->apply($this->record(), $this->connection());

        $this->assertTrue($applied);
        $this->assertSame(['debtor', 'creditor'], $projection->appliedKeys);
    }

    #[Test]
    public function skips_an_event_with_no_keys(): void
    {
        $projection = new RecordingGroupedReadModel([]);

        $applied = $projection->apply($this->record(), $this->connection());

        $this->assertFalse($applied);
        $this->assertSame([], $projection->appliedKeys);
    }

    #[Test]
    public function a_fold_that_applies_nothing_reports_false_for_its_only_key(): void
    {
        // the aggregate verdict is an OR over the keys, seeded false: one key whose fold was a
        // no-op must NOT read as applied
        $projection = new RecordingGroupedReadModel(['k1'], ['k1' => false]);

        $this->assertFalse($projection->apply($this->record(), $this->connection()));
    }

    #[Test]
    public function collapses_duplicate_keys_to_one_fold(): void
    {
        // a self-transfer resolves both parties to the same view; folding twice would double-count
        $projection = new RecordingGroupedReadModel(['same', 'same']);

        $applied = $projection->apply($this->record(), $this->connection());

        $this->assertTrue($applied);
        $this->assertSame(['same'], $projection->appliedKeys);
    }

    #[Test]
    public function reports_applied_when_any_key_folds(): void
    {
        $projection = new RecordingGroupedReadModel(
            ['noop', 'folded'],
            ['noop' => false, 'folded' => true],
        );

        $this->assertTrue($projection->apply($this->record(), $this->connection()));
    }

    #[Test]
    public function reports_a_no_op_when_no_key_folds(): void
    {
        $projection = new RecordingGroupedReadModel(
            ['noop', 'other'],
            ['noop' => false, 'other' => false],
        );

        $this->assertFalse($projection->apply($this->record(), $this->connection()));
    }

    #[Test]
    #[Group('adversarial')]
    public function refuses_an_empty_string_key(): void
    {
        // the skip channel is the empty LIST; an empty string is a malformed extraction that
        // would otherwise silently starve a row forever
        $projection = new RecordingGroupedReadModel(['']);

        $this->expectException(InvalidGroupKey::class);

        $projection->apply($this->record(), $this->connection());
    }

    #[Test]
    public function generation_defaults_to_one(): void
    {
        // the default shape version; a concrete read model overrides it to bump when a change makes
        // the built rows incompatible and a full rebuild is required
        $this->assertSame(1, new RecordingGroupedReadModel([])->generation());
    }

    private function record(): EventRecord
    {
        return new EventRecord(
            new Message(new stdClass),
            SequencePosition::fromInt(1),
            PointInTime::from('2026-07-05T10:00:00.000000+00:00'),
        );
    }

    private function connection(): Connection
    {
        return $this->createStub(Connection::class);
    }
}
