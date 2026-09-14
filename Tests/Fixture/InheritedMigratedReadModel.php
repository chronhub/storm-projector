<?php

declare(strict_types=1);

namespace Storm\Projector\Tests\Fixture;

use Storm\Projector\Definition\MigratedReadModelBehavior;

abstract readonly class InheritedMigratedReadModel
{
    use MigratedReadModelBehavior;
}
