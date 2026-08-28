<?php

declare(strict_types=1);

namespace Storm\Projector\Tests\Fixture;

use Doctrine\DBAL\Connection;
use Storm\Chronicler\Record\EventRecord;
use Storm\Projector\Definition\DerivedStreamProjection;
use Storm\Projector\Definition\ReadModel;
use Storm\Stream\StreamName;

/**
 * A minimal DerivedStream CONSUMER shape, DerivedStreamProjection plus ReadModel, for the homing
 * tests: it WRITES a read model, so it homes store-side; its join-read stays events-side either way,
 * executed by the StreamReader where event_links lives.
 */
final class StubDerivedConsumer implements DerivedStreamProjection, ReadModel
{
    public function name(): string
    {
        return 'stub_derived_consumer';
    }

    public function generation(): int
    {
        return 1;
    }

    public function sourceStream(): StreamName
    {
        return new StreamName('stub-link-target');
    }

    public function initialize(Connection $tx): void {}

    public function clear(Connection $tx): void {}

    public function drop(Connection $tx): void {}

    public function apply(EventRecord $event, Connection $tx): bool
    {
        return true;
    }
}
