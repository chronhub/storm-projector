<?php

declare(strict_types=1);

namespace Storm\Projector\Store;

use Doctrine\DBAL\Exception;
use JsonException;

/**
 * The read-only store facet: rows and lease liveness for status surfaces, listings, and freshness
 * probes. Grants no mutation, so a consumer hinting this facet can be handed the homed router
 * without receiving checkpoint or lease capabilities it must not hold.
 */
interface ProjectionCatalog
{
    /**
     * Whether a worker currently holds a live, unexpired lease, the source of truth for "is it running?",
     * since status can be stale. Guards reset/delete.
     *
     * @throws Exception
     */
    public function hasLiveLease(string $name): bool;

    /**
     * Read one projection's state row, or null when the name has no row.
     *
     * @throws JsonException
     * @throws Exception
     */
    public function findRow(string $name): ?ProjectionRow;

    /**
     * Every projection's state row, name-ordered, for the `storm:projection:status` listing.
     *
     * @return list<ProjectionRow>
     *
     * @throws Exception
     */
    public function all(): array;
}
