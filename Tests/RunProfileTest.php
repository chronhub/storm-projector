<?php

declare(strict_types=1);

namespace Storm\Projector\Tests;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Chronicler\Evolution\IdentityEventTypeMapper;
use Storm\Chronicler\Record\EventRecord;
use Storm\Projector\Definition\DerivedStreamProjection;
use Storm\Projector\Definition\FilteredProjection;
use Storm\Projector\Definition\PersistentProjection;
use Storm\Projector\Exception\UnsupportedProjection;
use Storm\Projector\Run\RunOptions;
use Storm\Projector\Run\RunProfile;
use Storm\Stream\StreamName;

/**
 * `RunProfile::for` resolves a persistent projection's selection, exactly one of FilteredProjection
 * for categories/types or DerivedStreamProjection for a source stream. Declaring neither or both is
 * rejected by the selection guard.
 */
final class RunProfileTest extends TestCase
{
    #[Test]
    public function builds_a_filtered_profile_from_categories_and_types(): void
    {
        $profile = RunProfile::for($this->filtered(), new RunOptions(owner: 'test'), eventTypeMapper: new IdentityEventTypeMapper);

        $this->assertSame(['account'], $profile->categories);
        $this->assertNull($profile->sourceStream);
    }

    #[Test]
    public function builds_a_derived_stream_profile_from_the_source_stream(): void
    {
        $profile = RunProfile::for($this->derived(), new RunOptions(owner: 'test'), eventTypeMapper: new IdentityEventTypeMapper);

        $this->assertSame('large_withdrawals', $profile->sourceStream?->toString());
        $this->assertSame([], $profile->categories);
    }

    #[Test]
    public function rejects_a_projection_with_no_selection(): void
    {
        $projection = new class() implements PersistentProjection
        {
            public function name(): string
            {
                return 'no_selection';
            }

            public function generation(): int
            {
                return 1;
            }

            public function apply(EventRecord $event, Connection $tx): bool
            {
                return true;
            }

            public function initialize(Connection $tx): void {}

            public function clear(Connection $tx): void {}

            public function drop(Connection $tx): void {}
        };

        $this->expectException(UnsupportedProjection::class);
        $this->expectExceptionMessageIsOrContains('exactly one selection');

        RunProfile::for($projection, new RunOptions(owner: 'test'), eventTypeMapper: new IdentityEventTypeMapper);
    }

    #[Test]
    public function rejects_a_projection_declaring_both_selections(): void
    {
        $projection = new class() implements DerivedStreamProjection, FilteredProjection
        {
            public function name(): string
            {
                return 'dual';
            }

            public function generation(): int
            {
                return 1;
            }

            public function apply(EventRecord $event, Connection $tx): bool
            {
                return true;
            }

            public function categories(): array
            {
                return ['account'];
            }

            public function eventTypes(): array
            {
                return [];
            }

            public function sourceStream(): StreamName
            {
                return new StreamName('feed');
            }

            public function initialize(Connection $tx): void {}

            public function clear(Connection $tx): void {}

            public function drop(Connection $tx): void {}
        };

        $this->expectException(UnsupportedProjection::class);

        RunProfile::for($projection, new RunOptions(owner: 'test'), eventTypeMapper: new IdentityEventTypeMapper);
    }

    private function filtered(): FilteredProjection
    {
        return new class() implements FilteredProjection
        {
            public function name(): string
            {
                return 'filtered';
            }

            public function generation(): int
            {
                return 1;
            }

            public function apply(EventRecord $event, Connection $tx): bool
            {
                return true;
            }

            public function categories(): array
            {
                return ['account'];
            }

            public function eventTypes(): array
            {
                return [];
            }

            public function initialize(Connection $tx): void {}

            public function clear(Connection $tx): void {}

            public function drop(Connection $tx): void {}
        };
    }

    private function derived(): DerivedStreamProjection
    {
        return new class() implements DerivedStreamProjection
        {
            public function name(): string
            {
                return 'derived';
            }

            public function generation(): int
            {
                return 1;
            }

            public function apply(EventRecord $event, Connection $tx): bool
            {
                return true;
            }

            public function sourceStream(): StreamName
            {
                return new StreamName('large_withdrawals');
            }

            public function initialize(Connection $tx): void {}

            public function clear(Connection $tx): void {}

            public function drop(Connection $tx): void {}
        };
    }
}
