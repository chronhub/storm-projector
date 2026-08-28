<?php

declare(strict_types=1);

namespace Storm\Projector\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Projector\Run\RunOptions;
use Storm\Projector\Run\RunProfile;
use Storm\Projector\Run\TopologyDrift;
use Storm\Projector\Store\ProjectionRow;
use Storm\Projector\Store\ProjectionStatus;
use Storm\Projector\Tests\Fixture\StubFilteredProjection;
use Storm\Stream\StreamName;

/**
 * The topology comparison in isolation: which stored-vs-declared differences count as drift, namely
 * mode, categories, event classes and source stream compared as sets, and which never do, namely
 * declaration order, duplicates and an alias-moved resolved type, since classes are the anchor.
 */
final class TopologyDriftTest extends TestCase
{
    #[Test]
    public function an_identical_topology_has_no_drift(): void
    {
        $row = $this->row(categories: ['account'], eventClasses: ['MoneyDeposited']);

        $this->assertSame([], TopologyDrift::between($row, 'filtered', $this->profile(categories: ['account'], eventClasses: ['MoneyDeposited'])));
    }

    #[Test]
    public function declaration_order_and_duplicates_are_not_topology(): void
    {
        $row = $this->row(categories: ['account', 'identity'], eventClasses: ['A', 'B']);

        $declared = $this->profile(categories: ['identity', 'account'], eventClasses: ['B', 'A', 'B']);

        $this->assertSame([], TopologyDrift::between($row, 'filtered', $declared));
    }

    #[Test]
    public function a_widened_category_set_is_drift(): void
    {
        $row = $this->row(categories: ['account'], eventClasses: []);

        $axes = TopologyDrift::between($row, 'filtered', $this->profile(categories: ['account', 'identity'], eventClasses: []));

        $this->assertCount(1, $axes);
        $this->assertStringContainsString('categories', $axes[0]);
    }

    #[Test]
    public function a_narrowed_event_class_set_is_drift(): void
    {
        // [] means "all types": restricting to an explicit list reads a different perimeter
        $row = $this->row(categories: ['account'], eventClasses: []);

        $axes = TopologyDrift::between($row, 'filtered', $this->profile(categories: ['account'], eventClasses: ['MoneyDeposited']));

        $this->assertCount(1, $axes);
        $this->assertStringContainsString('event classes', $axes[0]);
    }

    #[Test]
    public function a_mode_change_is_drift(): void
    {
        $row = $this->row(categories: [], eventClasses: []);

        $axes = TopologyDrift::between($row, 'derived', $this->profile(categories: [], eventClasses: []));

        $this->assertCount(1, $axes);
        $this->assertStringContainsString('mode', $axes[0]);
    }

    #[Test]
    public function a_switched_source_stream_is_drift(): void
    {
        $row = $this->row(categories: [], eventClasses: [], sourceStream: new StreamName('account-feed'));

        $declared = $this->profile(categories: [], eventClasses: [], sourceStream: new StreamName('order-feed'));

        $axes = TopologyDrift::between($row, 'filtered', $declared);

        $this->assertCount(1, $axes);
        $this->assertStringContainsString('source stream', $axes[0]);
    }

    #[Test]
    public function a_dropped_source_stream_is_drift(): void
    {
        $row = $this->row(categories: [], eventClasses: [], sourceStream: new StreamName('account-feed'));

        $axes = TopologyDrift::between($row, 'filtered', $this->profile(categories: [], eventClasses: []));

        $this->assertCount(1, $axes);
        $this->assertStringContainsString('source stream', $axes[0]);
    }

    #[Test]
    public function every_drifted_axis_is_reported(): void
    {
        $row = $this->row(categories: ['account'], eventClasses: ['A']);

        $axes = TopologyDrift::between($row, 'derived', $this->profile(categories: ['identity'], eventClasses: ['B'], sourceStream: new StreamName('a-feed')));

        $this->assertCount(4, $axes); // mode, categories, event classes, source stream
    }

    #[Test]
    #[Group('adversarial')]
    public function a_renamed_fixed_target_is_drift(): void
    {
        // output IS checkpoint-bound; a target rename over a non-zero checkpoint would send only
        // the events past it to the new stream, hollow and history-less, and orphan the old one
        $row = $this->row(categories: ['account'], eventClasses: [], targetStream: new StreamName('feed-v1'));

        $axes = TopologyDrift::between($row, 'filtered', $this->profile(categories: ['account'], eventClasses: []), new StreamName('feed-v2'));

        $this->assertCount(1, $axes);
        $this->assertStringContainsString('target stream', $axes[0]);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_changed_fan_out_prefix_is_drift(): void
    {
        $row = $this->row(categories: ['account'], eventClasses: [], targetPrefix: 'et-');

        $axes = TopologyDrift::between($row, 'filtered', $this->profile(categories: ['account'], eventClasses: []), null, 'audit-');

        $this->assertCount(1, $axes);
        $this->assertStringContainsString('target prefix', $axes[0]);
    }

    #[Test]
    public function a_dropped_fan_out_prefix_names_both_sides(): void
    {
        // the report renders the absent side as the word none, the present side quoted: an operator
        // reads WHICH side lost its prefix, not two interchangeable blanks
        $row = $this->row(categories: ['account'], eventClasses: [], targetPrefix: 'et-');

        $axes = TopologyDrift::between($row, 'filtered', $this->profile(categories: ['account'], eventClasses: []), null, null);

        $this->assertCount(1, $axes);
        $this->assertStringContainsString('"et-"', $axes[0]);
        $this->assertStringContainsString('none', $axes[0]);
    }

    #[Test]
    public function an_unchanged_target_is_not_drift(): void
    {
        $row = $this->row(categories: ['account'], eventClasses: [], targetStream: new StreamName('feed-v1'));

        $this->assertSame([], TopologyDrift::between($row, 'filtered', $this->profile(categories: ['account'], eventClasses: []), new StreamName('feed-v1')));
    }

    /**
     * @param  list<string>  $categories
     * @param  list<string>  $eventClasses
     */
    private function row(array $categories, array $eventClasses, ?StreamName $sourceStream = null, ?StreamName $targetStream = null, ?string $targetPrefix = null): ProjectionRow
    {
        return new ProjectionRow(
            name: 'topology_rm',
            status: ProjectionStatus::Idle,
            lastPosition: 42,
            mode: 'filtered',
            categories: $categories,
            eventClasses: $eventClasses,
            sourceStream: $sourceStream,
            sourceRevision: 0,
            targetStream: $targetStream,
            targetPrefix: $targetPrefix,
            leaseOwner: null,
            leaseUntil: null,
            lastHeartbeatAt: null,
            pauseUntil: null,
            generation: 1,
        );
    }

    /**
     * @param  list<string>  $categories
     * @param  list<string>  $eventClasses
     */
    private function profile(array $categories, array $eventClasses, ?StreamName $sourceStream = null): RunProfile
    {
        // built directly: the comparison only reads the topology fields; resolved $types are
        // deliberately absent from it, since classes are the anchor and the expansion is derived
        return new RunProfile(
            new StubFilteredProjection('topology_rm'),
            'topology_rm',
            $categories,
            [],
            $eventClasses,
            $sourceStream,
            new RunOptions(owner: 'test'),
        );
    }
}
