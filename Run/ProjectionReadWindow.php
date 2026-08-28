<?php

declare(strict_types=1);

namespace Storm\Projector\Run;

use Storm\Stream\StreamName;

/**
 * One read window of a projection run: the selection and the bounds of a single page. The
 * selection is exactly one of two shapes, `sourceStream` set for a derived consumer folding its
 * producer's links, or the category and stored-type lists for a plain filtered read; the run
 * profile's XOR guarantees the shape, so the window carries it without re-judging it.
 */
final readonly class ProjectionReadWindow
{
    /**
     * @param  int  $after  exclusive start, the run's checkpoint
     * @param  int  $max  inclusive frontier, already capped by watermark and target
     * @param  positive-int  $limit  the page cap; a zero would reach the store as `LIMIT 0`, an
     *                               empty page indistinguishable from a drained window
     * @param  list<string>  $categories
     * @param  list<string>  $types
     */
    public function __construct(
        public int $after,
        public int $max,
        public int $limit,
        public array $categories = [],
        public array $types = [],
        public ?StreamName $sourceStream = null,
    ) {}
}
