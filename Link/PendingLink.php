<?php

declare(strict_types=1);

namespace Storm\Projector\Link;

use Storm\Stream\StreamName;

/**
 * One link a batch means to write: a source event's global position and the derived stream it joins.
 * The unit of `EventLinkWriter::linkMany()`, decided by the projection, written by the writer.
 */
final readonly class PendingLink
{
    public function __construct(
        public int $sourceSequence,
        public StreamName $target,
    ) {}
}
