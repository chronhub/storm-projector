<?php

declare(strict_types=1);

namespace Storm\Projector\Tests\Registry;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Projector\Exception\DuplicateProjection;
use Storm\Projector\Exception\InvalidDerivedNamespace;
use Storm\Projector\Exception\UnknownProjection;
use Storm\Projector\Exception\UnsupportedProjection;
use Storm\Projector\Registry\ProjectionRegistry;
use Storm\Projector\Tests\Fixture\FakeProjection;
use Storm\Projector\Tests\Fixture\StubFanOutProjection;
use Storm\Projector\Tests\Fixture\StubFilteredProjection;
use Storm\Projector\Tests\Fixture\StubLinkProjection;

final class ProjectionRegistryTest extends TestCase
{
    #[Test]
    public function indexes_projections_by_name(): void
    {
        $balance = new FakeProjection('account_balance');
        $ledger = new FakeProjection('account_ledger');

        $registry = new ProjectionRegistry([$balance, $ledger]);

        $this->assertSame($balance, $registry->get('account_balance'));
        $this->assertSame($ledger, $registry->get('account_ledger'));
        $this->assertTrue($registry->has('account_balance'));
        $this->assertFalse($registry->has('nope'));
        $this->assertSame(['account_balance', 'account_ledger'], $registry->names());
        $this->assertCount(2, $registry->all());
    }

    #[Test]
    public function throws_for_an_unknown_name(): void
    {
        $this->expectException(UnknownProjection::class);

        new ProjectionRegistry([])->get('missing');
    }

    #[Test]
    public function rejects_two_projections_with_the_same_name(): void
    {
        $this->expectException(DuplicateProjection::class);

        new ProjectionRegistry([new FakeProjection('dup'), new FakeProjection('dup')]);
    }

    #[Test]
    public function rejects_a_persistent_projection_with_a_non_positive_generation(): void
    {
        // 0 is the unstamped sentinel: a projection declaring it would be re-stamped 0 on every run
        // and the staleness gate would silently never fire; fail fast at setup instead
        $this->expectException(UnsupportedProjection::class);
        $this->expectExceptionMessageIsOrContains('generation() = 0');

        new ProjectionRegistry([new StubFilteredProjection('zero_gen', generation: 0)]);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_two_link_projections_sharing_a_fixed_target(): void
    {
        // a derived target has exactly one producer; the max(target_position)+1 allocation and the
        // reset/delete both assume it; two producers would race and one reset would take the other's links
        $this->expectException(InvalidDerivedNamespace::class);

        new ProjectionRegistry([
            new StubLinkProjection('a', 'shared-target'),
            new StubLinkProjection('b', 'shared-target'),
        ]);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_an_empty_fan_out_prefix(): void
    {
        // starts_with(x, '') matches every row, so its reset would erase ALL derived streams
        $this->expectException(InvalidDerivedNamespace::class);

        new ProjectionRegistry([new StubFanOutProjection('fan', '')]);
    }

    #[Test]
    public function rejects_a_whitespace_fan_out_prefix(): void
    {
        // trimmed on purpose: a blank-only prefix is the empty prefix wearing spaces
        $this->expectException(InvalidDerivedNamespace::class);

        new ProjectionRegistry([new StubFanOutProjection('fan', '   ')]);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_overlapping_fan_out_prefixes(): void
    {
        // one prefix is a prefix of the other, so resetting one deletes the other's links
        $this->expectException(InvalidDerivedNamespace::class);

        new ProjectionRegistry([
            new StubFanOutProjection('a', 'feed-'),
            new StubFanOutProjection('b', 'feed-eu-'),
        ]);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_fixed_target_covered_by_a_fan_out_prefix(): void
    {
        // resetting the fan-out, a delete by prefix, would take the fixed producer's links too
        $this->expectException(InvalidDerivedNamespace::class);

        new ProjectionRegistry([
            new StubFanOutProjection('fan', 'feed-'),
            new StubLinkProjection('fixed', 'feed-summary'),
        ]);
    }

    #[Test]
    public function accepts_disjoint_owned_derived_namespaces(): void
    {
        $registry = new ProjectionRegistry([
            new StubLinkProjection('a', 'account-feed'),
            new StubFanOutProjection('b', 'et-'),
            new StubFanOutProjection('c', 'audit-'),
        ]);

        $this->assertCount(3, $registry->all());
    }

    #[Test]
    public function is_empty_with_no_projections(): void
    {
        $registry = new ProjectionRegistry;

        $this->assertSame([], $registry->all());
        $this->assertSame([], $registry->names());
    }
}
