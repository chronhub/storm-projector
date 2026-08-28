<?php

declare(strict_types=1);

namespace Storm\Projector\Run;

use Doctrine\DBAL\Connection;
use LogicException;
use Storm\Projector\Store\ProjectionStore;

/**
 * One home's runtime kit: the checkpoint store, the connection the batch tx runs on, which
 * `apply()` also receives, and the pipeline whose write-side stages are bound to both. ReadBatch
 * is shared across lanes; events are ALWAYS read on the events connection.
 *
 * @see ProjectionHome
 * @see ProjectionLanes
 */
final readonly class ProjectionLane
{
    /**
     * @param  PipelineFactory|null  $pipeline  run-path wiring; a manually-built lane that never
     *                                          runs batches may omit it
     */
    public function __construct(
        public ProjectionStore $store,
        public Connection $connection,
        public ?PipelineFactory $pipeline = null,
    ) {}

    /**
     * The run path REQUIRES the pipeline: fail with the wiring story, not a null deref.
     *
     * @throws LogicException when the lane was wired without a PipelineFactory
     */
    public function runPipeline(): PipelineFactory
    {
        return $this->pipeline ?? throw new LogicException('This projection lane was wired store-only, with no PipelineFactory. Running projections needs the full lane kit; see the Projector package config/services.php.');
    }
}
