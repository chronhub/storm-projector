<?php

declare(strict_types=1);

namespace Storm\Projector\Tests;

use ArrayObject;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Storm\Contracts\Projector\ProjectionCommitListener;
use Storm\Projector\Run\CompositeProjectionCommitListener;

final class CompositeProjectionCommitListenerTest extends TestCase
{
    #[Test]
    public function every_listener_receives_the_commit_in_registration_order(): void
    {
        /** @var ArrayObject<int, string> $log */
        $log = new ArrayObject;

        $composite = new CompositeProjectionCommitListener([
            self::recording('purger', $log),
            self::recording('metrics', $log),
        ]);

        $composite->committed('account_balance');

        $this->assertSame(['purger:account_balance', 'metrics:account_balance'], $log->getArrayCopy());
    }

    #[Test]
    #[Group('adversarial')]
    public function a_throwing_listener_does_not_starve_its_siblings_and_the_failure_still_surfaces(): void
    {
        // the port's clause is MUST NOT throw, so a throwing delegate is already a violation; the
        // composite's job is to keep the violation from also robbing the SIBLINGS of their commit
        // signal, while the relayed first failure keeps the runner's observability net informed
        /** @var ArrayObject<int, string> $log */
        $log = new ArrayObject;

        $composite = new CompositeProjectionCommitListener([
            new class() implements ProjectionCommitListener
            {
                public function committed(string $projection): void
                {
                    throw new RuntimeException('purger down');
                }
            },
            self::recording('metrics', $log),
            new class() implements ProjectionCommitListener
            {
                public function committed(string $projection): void
                {
                    throw new RuntimeException('nudge down too');
                }
            },
        ]);

        try {
            $composite->committed('account_balance');
            $this->fail('expected the delegate failure to be relayed after the loop');
        } catch (RuntimeException $e) {
            $this->assertSame('purger down', $e->getMessage(), 'the FIRST failure is the one relayed, later ones never mask it');
        }

        $this->assertSame(['metrics:account_balance'], $log->getArrayCopy(), 'the sibling was served despite the earlier failure');
    }

    /**
     * @param  ArrayObject<int, string>  $log
     */
    private static function recording(string $name, ArrayObject $log): ProjectionCommitListener
    {
        return new readonly class($name, $log) implements ProjectionCommitListener
        {
            /**
             * @param  ArrayObject<int, string>  $log
             */
            public function __construct(private string $name, private ArrayObject $log) {}

            public function committed(string $projection): void
            {
                $this->log->append($this->name.':'.$projection);
            }
        };
    }
}
