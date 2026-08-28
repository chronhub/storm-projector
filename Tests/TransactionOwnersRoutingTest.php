<?php

declare(strict_types=1);

namespace Storm\Projector\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use Storm\Projector\Management\ProjectionManagement;
use Storm\Projector\Run\ProjectionRunner;
use Storm\Projector\Store\HomedProjectionStore;
use Storm\Projector\Store\ProjectionStore;

/**
 * Wiring guard, not a behavior test: HomedProjectionStore's own invariant, "transaction owners never
 * route", holds today only because ProjectionRunner and ProjectionManagement are never even WIRED the
 * router, ProjectionStore aliasing to HomedProjectionStore; both resolve a lane's own store instead via
 * `$lanes->laneFor(...)->store` or `$lane->store`, so acquireCheckpoint / renewLease / resumeIfElapsed /
 * reset / stampGeneration / retry / delete / lockAndAssertNotRunning never cross the router.
 *
 * There is no meaningful behavior test for "nobody calls the router this way": nobody does, today, and
 * a 9th delegation test identical to HomedProjectionStoreTest's existing ones would prove nothing new.
 * What actually protects the invariant is that neither class HOLDS a reference to the router at all; this
 * pins that structural precondition, so a future refactor that adds a ProjectionStore-typed dependency to
 * either class, the first step that would make a routed destructive call possible, breaks this test
 * immediately, before the routed call itself has to be caught in review.
 *
 * @see HomedProjectionStore
 */
final class TransactionOwnersRoutingTest extends TestCase
{
    #[Test]
    public function neither_transaction_owner_is_wired_the_routing_store(): void
    {
        foreach ([ProjectionRunner::class, ProjectionManagement::class] as $class) {
            $constructor = new ReflectionClass($class)->getConstructor();
            self::assertNotNull($constructor, $class.' has no constructor to inspect');

            foreach ($constructor->getParameters() as $parameter) {
                $type = $parameter->getType();
                $typeName = $type instanceof ReflectionNamedType ? $type->getName() : null;

                self::assertNotSame(
                    ProjectionStore::class,
                    $typeName,
                    sprintf(
                        '%s must never depend on %s (the routing store) — it must resolve a LANE store via ProjectionLanes instead.',
                        $class,
                        ProjectionStore::class,
                    ),
                );
            }
        }
    }
}
