<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Doctrine\DBAL\Connection;
use Storm\Projector\Registry\ProjectionRegistry;
use Storm\Projector\Run\PipelineFactory;
use Storm\Projector\Run\ProjectionLane;
use Storm\Projector\Run\ProjectionLanes;
use Storm\Projector\Run\Stage\AcquireCheckpoint;
use Storm\Projector\Run\Stage\ApplyBatch;
use Storm\Projector\Run\Stage\Checkpoint;
use Storm\Projector\Run\Stage\ReadBatch;
use Storm\Projector\Store\DbalProjectionStore;
use Storm\Projector\Store\HomedProjectionStore;
use Storm\Projector\Store\ProjectionCatalog;
use Storm\Projector\Store\ProjectionLifecycleStore;

/*
 * Projector package wiring: per-projection connection homing.
 *
 * Two named connections, declared HERE so the package stays standalone. The events alias is
 * ALWAYS the default connection: safe-head and event_links live with the event store. The
 * read-model store alias defaults to the same connection and the bundle re-points it when
 * `storm.connections.read_model_store` names a doctrine connection; single database means both
 * aliases resolve to one connection and every lane collapses.
 *
 * The surfaces split in two families. Transaction owners, the runner and management, take
 * ProjectionLanes and run each projection's tx on ITS home. Everything else speaks the
 * ProjectionStore port, aliased to the HomedProjectionStore router, and never learns that homes
 * exist. ReadBatch is shared: events are always read where they live.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->alias('storm.projector.events_connection', Connection::class);
    $services->alias('storm.read_model_store_connection', Connection::class);

    $services = $services
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $services->load('Storm\\Projector\\', dirname(__DIR__).'/')
        ->exclude([
            dirname(__DIR__).'/Definition/', // the authoring surface: contracts, base classes and the discovery attribute; the app's concretes are the services
            dirname(__DIR__).'/Run/RunOptions.php',
            dirname(__DIR__).'/Run/RunProfile.php',
            dirname(__DIR__).'/Run/RunState.php',
            dirname(__DIR__).'/Run/Pipeline.php',
            dirname(__DIR__).'/Run/ProjectionHome.php', // an enum, the kind rule, not a service
            dirname(__DIR__).'/Run/ProjectionLane.php', // built explicitly below, per home
            dirname(__DIR__).'/Run/ProjectionLanes.php', // built explicitly below
            dirname(__DIR__).'/Run/PipelineFactory.php', // built explicitly below, per home
            dirname(__DIR__).'/Run/Stage/AcquireCheckpoint.php', // per-home, inlined in its lane's pipeline
            dirname(__DIR__).'/Run/Stage/ApplyBatch.php', // per-home, inlined in its lane's pipeline
            dirname(__DIR__).'/Run/Stage/Checkpoint.php', // per-home, inlined in its lane's pipeline
            dirname(__DIR__).'/Store/DbalProjectionStore.php', // built explicitly below, one per home
            dirname(__DIR__).'/Store/ProjectionStatus.php',
            dirname(__DIR__).'/Store/ProjectionRow.php',
            dirname(__DIR__).'/Console/MarkerProjectionCommand.php', // abstract base; the concrete mark:* commands are the services
            dirname(__DIR__).'/Run/ProjectionReadWindow.php', // a per-page value, never a service
            dirname(__DIR__).'/Run/NullProjectionCommitListener.php', // the runner's parameter default, not a collected listener
            dirname(__DIR__).'/Run/CompositeProjectionCommitListener.php', // built explicitly by the bundle over the tagged set; tagging it would make it collect itself
            dirname(__DIR__).'/Run/PreparedRun.php', // the preflight's verdict, a value
            dirname(__DIR__).'/Run/ProjectionRunPreflight.php', // composed INSIDE the runner, one entry point
            dirname(__DIR__).'/Run/RunOutcome.php', // a run's verdict, a value
            dirname(__DIR__).'/Run/RunRefusal.php', // the preflight's refusal, a value
            dirname(__DIR__).'/Run/StandDown.php', // a cycle's stand-down verdict, a value
            dirname(__DIR__).'/Run/CycleDecision.php', // a cycle's continue-or-stop verdict, a value
            dirname(__DIR__).'/Run/FilterTypes.php', // static expansion helper, never instantiated
            dirname(__DIR__).'/Query/QueryFold.php', // built per ad-hoc query, never a service
            dirname(__DIR__).'/Query/QueryRunOptions.php',
            dirname(__DIR__).'/Query/QueryRunMode.php', // an enum
            dirname(__DIR__).'/Store/ProjectionMode.php', // an enum
            // per-call observation payloads handed to the telemetry port, not services
            dirname(__DIR__).'/Telemetry/BatchContext.php',
            dirname(__DIR__).'/Telemetry/RunContext.php',
            dirname(__DIR__).'/Telemetry/ListenerFailureContext.php',
            dirname(__DIR__).'/Schema/',
            dirname(__DIR__).'/Exception/',
            dirname(__DIR__).'/config/',
            dirname(__DIR__).'/Testing/', // consumer-facing test kit, never container services
            dirname(__DIR__).'/Tests/',
        ]);

    // the seam: the wired world reads through the Chronicler-backed source
    $services->alias(\Storm\Projector\Run\ProjectionEventSource::class, \Storm\Projector\Run\ChroniclerEventSource::class);

    // the two homes: one Dbal store per connection
    $services->set('storm.projector.store.events', DbalProjectionStore::class)
        ->args([service('storm.projector.events_connection')]);
    $services->set('storm.projector.store.read_models', DbalProjectionStore::class)
        ->args([service('storm.read_model_store_connection')]);

    // the routed port every non-tx surface speaks; the router collapses under a single database
    $services->set(HomedProjectionStore::class)->args([
        service(ProjectionLanes::class),
        service(ProjectionRegistry::class),
    ]);
    // the operator and read surfaces autowire their FACET onto the homed router; the run facet
    // has no alias on purpose, a runner receives its lane-local store or nothing
    $services->alias(ProjectionCatalog::class, HomedProjectionStore::class);
    $services->alias(ProjectionLifecycleStore::class, HomedProjectionStore::class);

    // the transaction owners' kit: one full lane per home, write-side stages bound to the home,
    // ReadBatch shared across lanes since events are always read on the events connection
    $services->set('storm.projector.pipeline.events', PipelineFactory::class)->args([
        inline_service(AcquireCheckpoint::class)->args([service('storm.projector.store.events')]),
        service(ReadBatch::class),
        inline_service(ApplyBatch::class)->args([service('storm.projector.events_connection')]),
        inline_service(Checkpoint::class)->args([service('storm.projector.store.events')]),
    ]);
    $services->set('storm.projector.pipeline.read_models', PipelineFactory::class)->args([
        inline_service(AcquireCheckpoint::class)->args([service('storm.projector.store.read_models')]),
        service(ReadBatch::class),
        inline_service(ApplyBatch::class)->args([service('storm.read_model_store_connection')]),
        inline_service(Checkpoint::class)->args([service('storm.projector.store.read_models')]),
    ]);
    $services->set('storm.projector.lane.events', ProjectionLane::class)->args([
        service('storm.projector.store.events'),
        service('storm.projector.events_connection'),
        service('storm.projector.pipeline.events'),
    ]);
    $services->set('storm.projector.lane.read_models', ProjectionLane::class)->args([
        service('storm.projector.store.read_models'),
        service('storm.read_model_store_connection'),
        service('storm.projector.pipeline.read_models'),
    ]);
    $services->set(ProjectionLanes::class)->args([
        service('storm.projector.lane.events'),
        service('storm.projector.lane.read_models'),
    ]);
};
