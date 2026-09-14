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
use Storm\Projector\Telemetry\ListenerFailureContext;
use Storm\Projector\Telemetry\ProjectorObservability;
use Throwable;

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

    #[Test]
    public function two_failing_delegates_are_both_surfaced_to_observability(): void
    {
        $first = new RuntimeException('purger down');
        $second = new RuntimeException('application hook down');
        $failures = [];
        $obs = $this->createMock(ProjectorObservability::class);
        $obs->expects(self::exactly(2))->method('recordListenerFailure')->willReturnCallback(
            static function (ListenerFailureContext $context) use (&$failures): void {
                $failures[] = $context;
            },
        );
        $healthy = $this->createMock(ProjectionCommitListener::class);
        $healthy->expects(self::once())->method('committed')->with('account_balance');
        $composite = new CompositeProjectionCommitListener([
            self::failing($first),
            $healthy,
            self::failing($second),
        ], $obs);

        try {
            $composite->committed('account_balance');
            self::fail('The first failure must escape after fan-out.');
        } catch (Throwable $error) {
            self::assertSame($first, $error);
            $obs->recordListenerFailure(new ListenerFailureContext('account_balance', $error));
        }

        self::assertCount(2, $failures);
        self::assertSame([$second, $first], array_column($failures, 'error'));
        self::assertSame(['account_balance', 'account_balance'], array_column($failures, 'projection'));
    }

    #[Test]
    public function healthy_delegates_do_not_emit_failures(): void
    {
        $obs = $this->createMock(ProjectorObservability::class);
        $obs->expects(self::never())->method('recordListenerFailure');
        $healthy = $this->createMock(ProjectionCommitListener::class);
        $healthy->expects(self::once())->method('committed')->with('healthy');
        $this->expectOutputString('');

        new CompositeProjectionCommitListener([$healthy], $obs)->committed('healthy');
    }

    #[Test]
    public function a_single_failure_is_relayed_without_direct_observability(): void
    {
        $error = new RuntimeException('only failure');
        $obs = $this->createMock(ProjectorObservability::class);
        $obs->expects(self::never())->method('recordListenerFailure');
        $composite = new CompositeProjectionCommitListener([self::failing($error)], $obs);
        $this->expectExceptionObject($error);

        $composite->committed('account_balance');
    }

    private static function failing(Throwable $error): ProjectionCommitListener
    {
        return new readonly class($error) implements ProjectionCommitListener
        {
            public function __construct(private Throwable $error) {}

            public function committed(string $projection): void
            {
                throw $this->error;
            }
        };
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
