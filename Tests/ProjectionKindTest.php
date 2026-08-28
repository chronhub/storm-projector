<?php

declare(strict_types=1);

namespace Storm\Projector\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Projector\Definition\ProjectionKind;
use Storm\Projector\Tests\Fixture\FakeProjection;

final class ProjectionKindTest extends TestCase
{
    #[Test]
    public function a_direct_implementation_of_the_base_contract_keeps_the_neutral_kind(): void
    {
        self::assertSame(ProjectionKind::Projection, ProjectionKind::of(new FakeProjection('custom')));
    }
}
