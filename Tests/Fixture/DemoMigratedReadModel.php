<?php

declare(strict_types=1);

namespace Storm\Projector\Tests\Fixture;

use Doctrine\DBAL\Connection;
use Storm\Projector\Definition\MigratedReadModelBehavior;

/**
 * A minimal host for MigratedReadModelBehavior, enough to unit-test the trait's lifecycle without a
 * database: `tableExists()` is the seam, driven here by the constructor flag, so the handed connection
 * is never touched on the covered paths, initialize-via-the-seam and drop.
 *
 * It holds NO connection of its own, which is the trait's contract since the store split: the home
 * connection arrives as an argument, so a host cannot bind the wrong one.
 *
 * @see MigratedReadModelBehavior
 */
final readonly class DemoMigratedReadModel
{
    use MigratedReadModelBehavior;

    public function __construct(private bool $tablePresent) {}

    protected function tableName(): string
    {
        return 'rm_demo';
    }

    protected function tableExists(Connection $tx): bool
    {
        return $this->tablePresent;
    }
}
