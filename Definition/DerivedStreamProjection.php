<?php

declare(strict_types=1);

namespace Storm\Projector\Definition;

use Storm\Stream\StreamName;

/**
 * A `PersistentProjection` that folds a derived stream, the links a `LinkProjection` wrote into
 * `event_links`, instead of a category/type slice of `event_store`. The read-side mirror of
 * `LinkProjection::targetStream()`: the same physical stream, the producer writes it, this consumer
 * reads it.
 *
 * It checkpoints on `sequence_no` like a filtered projection: a single-producer derived stream is
 * monotone with `sequence_no`, since the producer links in `sequence_no` order, so the runner's
 * checkpoint, safe-head watermark and `scanMax` apply unchanged. The runner builds a join-filter from
 * `sourceStream()`. The selection is mutually exclusive with `FilteredProjection`, a persistent
 * projection implementing exactly one, so no categories/types here.
 *
 * @see LinkProjection
 * @see FilteredProjection
 */
interface DerivedStreamProjection extends PersistentProjection
{
    /**
     * The derived stream this projection folds; a `LinkProjection` target.
     */
    public function sourceStream(): StreamName;
}
