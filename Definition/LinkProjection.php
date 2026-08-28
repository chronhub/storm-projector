<?php

declare(strict_types=1);

namespace Storm\Projector\Definition;

use Storm\Projector\Link\EventLinkWriter;
use Storm\Stream\StreamName;

/**
 * A projection that emits event links into a derived/target stream. Persistent, since the links persist,
 * so it checkpoints and resumes; filtered like any other, the safe watermark and atomic batch making the
 * links dense without an anti-join or contiguity scan. `apply()` links the source event into
 * `targetStream()`.
 *
 * Output lifecycle, the `PersistentProjection` contract: `initialize()` is a no-op, since `event_links`
 * is framework-owned and created by migration, and `clear()` and `drop()` both delete this stream's links
 * via `EventLinkWriter::deleteLinks()`, the writer it already injects for `apply()`.
 *
 * @see PersistentProjection
 * @see EventLinkWriter::deleteLinks()
 */
interface LinkProjection extends FilteredProjection
{
    /**
     * The fixed derived stream this projection links every matched source event into.
     */
    public function targetStream(): StreamName;
}
