<?php

declare(strict_types=1);

namespace Storm\Projector\Tests\Link;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Projector\Exception\InvalidDerivedNamespace;
use Storm\Projector\Link\EventLinkWriter;

final class EventLinkWriterTest extends TestCase
{
    #[Test]
    #[Group('adversarial')]
    #[DataProvider('blankPrefixes')]
    public function refuses_a_blank_prefix_that_would_erase_every_derived_stream(string $prefix): void
    {
        // starts_with(x, '') is true for EVERY row: a blank prefix would erase all derived streams,
        // every fan-out's and every fixed target's, in one lifecycle op. The registry rejects an empty
        // targetPrefix() at startup; this is the last-line guard, refused before any DELETE is issued.
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('executeStatement');

        $this->expectException(InvalidDerivedNamespace::class);

        new EventLinkWriter()->deleteLinksByPrefix($connection, $prefix);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blankPrefixes(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => ['   ']; // blank once trimmed; the guard trims before comparing
    }
}
