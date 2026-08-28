<?php

declare(strict_types=1);

namespace Storm\Projector\Run;

use Storm\Projector\Run\Stage\AcquireCheckpoint;
use Storm\Projector\Run\Stage\ApplyBatch;
use Storm\Projector\Run\Stage\Checkpoint;
use Storm\Projector\Run\Stage\ReadBatch;

/**
 * Assembles the per-batch `Pipeline` from its ordered stages, in sequence: `AcquireCheckpoint` locks the
 * checkpoint, then `ReadBatch` does the bounded read, then `ApplyBatch` applies each record, then
 * `Checkpoint` advances in-tx. Owns the stage order so the `ProjectionRunner` does not have to, a lighter
 * ctor and one place to change the order. The `Pipeline` is stateless, so `create()` is safe to call once
 * per run.
 *
 * @see ProjectionRunner
 */
final readonly class PipelineFactory
{
    public function __construct(
        private AcquireCheckpoint $acquireCheckpoint,
        private ReadBatch $readBatch,
        private ApplyBatch $applyBatch,
        private Checkpoint $checkpoint,
    ) {}

    public function create(): Pipeline
    {
        return new Pipeline([$this->acquireCheckpoint, $this->readBatch, $this->applyBatch, $this->checkpoint]);
    }
}
