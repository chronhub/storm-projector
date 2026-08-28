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
use Storm\Projector\Definition\AbstractFanOutLinkProjection;
use Storm\Projector\Exception\InvalidDerivedNamespace;
use Storm\Projector\Link\EventLinkWriter;
use Storm\Stream\StreamName;

final class AbstractFanOutLinkProjectionTest extends TestCase
{
    #[Test]
    #[Group('adversarial')]
    public function apply_refuses_a_target_outside_the_declared_prefix(): void
    {
        // targetFor() is dynamic, unvalidatable at startup like the prefix itself: a target outside the
        // prefix would be written here but MISSED by the prefix-scoped clear/reset, leaking output. The
        // guard refuses it at the write seam, before the link is ever inserted.
        $projection = $this->fanOut(target: new StreamName('wrong-1'), prefix: 'et-');

        $this->expectException(InvalidDerivedNamespace::class);

        $projection->apply($this->record(), $this->createStub(Connection::class));
    }

    #[Test]
    public function drop_deletes_every_link_under_the_declared_prefix(): void
    {
        // a fan-out produces many targets under one shared prefix, so drop clears the whole derived
        // namespace by starts_with(prefix), never a single target stream; the same delete as clear().
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('executeStatement')
            ->with($this->stringContains('starts_with(target_stream'), ['prefix' => 'et-']);

        $this->fanOut(target: null, prefix: 'et-')->drop($connection);
    }

    /**
     * A concrete fan-out over the abstract machinery: only targetFor()/targetPrefix() vary, so a test
     * drives the in-prefix, out-of-prefix and null-target paths of the shared apply()/clear()/drop().
     */
    private function fanOut(?StreamName $target, string $prefix = 'et-'): AbstractFanOutLinkProjection
    {
        return new class(new EventLinkWriter, $target, $prefix) extends AbstractFanOutLinkProjection
        {
            public function __construct(EventLinkWriter $linkWriter, private readonly ?StreamName $target, private readonly string $prefix)
            {
                parent::__construct($linkWriter);
            }

            public function name(): string
            {
                return 'fan_out_test';
            }

            public function categories(): array
            {
                return ['account'];
            }

            public function eventTypes(): array
            {
                return [];
            }

            public function targetFor(EventRecord $event): ?StreamName
            {
                return $this->target;
            }

            public function targetPrefix(): string
            {
                return $this->prefix;
            }
        };
    }

    private function record(): EventRecord
    {
        return new EventRecord(
            new Message(new stdClass),
            SequencePosition::fromInt(1),
            PointInTime::from('2026-07-05T10:00:00.000000+00:00'),
        );
    }
}
