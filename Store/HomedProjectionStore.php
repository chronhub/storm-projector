<?php

declare(strict_types=1);

namespace Storm\Projector\Store;

use Doctrine\DBAL\Exception;
use JsonException;
use Storm\Projector\Exception\DuplicateProjection;
use Storm\Projector\Exception\ProjectionHomeMismatch;
use Storm\Projector\Registry\ProjectionRegistry;
use Storm\Projector\Run\ProjectionHome;
use Storm\Projector\Run\ProjectionLanes;

use function array_map;
use function array_merge;
use function count;
use function usort;

/**
 * The routing store for the OPERATOR and CATALOG facets: every call lands on the row's HOME,
 * resolved from the projection's kind through the registry, so the surfaces that speak those
 * facets, the console, the waiter, the freshness seam, the ops resources, never learn that homes
 * exist. A single database collapses both homes onto one connection, and this router becomes a plain
 * pass-through.
 *
 * The run facet is deliberately ABSENT here: a run and an in-transaction lifecycle operation take
 * their lane's own store, since a routed call inside a lane transaction could cross connections.
 * Structurally, not by convention: this class does not implement `ProjectionRunStore`, so wiring
 * cannot hand the router to a runner.
 *
 * An orphaned row, one whose class was removed from the registry, is LOCATED by probing the homes:
 * with the code gone, the row's location is the only home signal left. A name found nowhere routes
 * to the read-model home, where a miss answers exactly what a single store would answer.
 *
 * The STATUS MARKERS, pause / resume / stop / retry / forceStatus, probe the other home before
 * routing, the same `assertHomedOn` run and reset already issue: routed by the current kind alone,
 * a marker after a kind flip would aim at the new home, find no row, and report "nothing to do"
 * while the real row, its status, and its lease live on the other database. Reads stay unprobed and
 * cheap, and `all()` detects the same fork after the fact when listing.
 */
final readonly class HomedProjectionStore implements ProjectionCatalog, ProjectionLifecycleStore
{
    public function __construct(
        private ProjectionLanes $lanes,
        private ProjectionRegistry $registry,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws ProjectionHomeMismatch when `$name`'s state sits on a home its current kind does not own
     */
    public function forceStatus(string $name, ProjectionStatus $status): void
    {
        $this->markerStoreFor($name)->forceStatus($name, $status);
    }

    /**
     * {@inheritDoc}
     *
     * @throws ProjectionHomeMismatch when `$name`'s state sits on a home its current kind does not own
     */
    public function requestStop(string $name, ProjectionStatus ...$from): ?ProjectionStatus
    {
        return $this->markerStoreFor($name)->requestStop($name, ...$from);
    }

    /**
     * {@inheritDoc}
     *
     * @throws ProjectionHomeMismatch when `$name`'s state sits on a home its current kind does not own
     */
    public function pause(string $name, ?int $forSeconds = null, ProjectionStatus ...$from): bool
    {
        return $this->markerStoreFor($name)->pause($name, $forSeconds, ...$from);
    }

    /**
     * {@inheritDoc}
     *
     * @throws ProjectionHomeMismatch when `$name`'s state sits on a home its current kind does not own
     */
    public function resume(string $name, ProjectionStatus ...$from): bool
    {
        return $this->markerStoreFor($name)->resume($name, ...$from);
    }

    public function reset(string $name, int $generation = 0): bool
    {
        return $this->storeFor($name)->reset($name, $generation);
    }

    /**
     * {@inheritDoc}
     *
     * @throws ProjectionHomeMismatch when `$name`'s state sits on a home its current kind does not own
     */
    public function retry(string $name, ProjectionStatus ...$from): bool
    {
        return $this->markerStoreFor($name)->retry($name, ...$from);
    }

    public function delete(string $name): bool
    {
        return $this->storeFor($name)->delete($name);
    }

    public function hasLiveLease(string $name): bool
    {
        return $this->storeFor($name)->hasLiveLease($name);
    }

    public function lockAndAssertNotRunning(string $name): void
    {
        $this->storeFor($name)->lockAndAssertNotRunning($name);
    }

    public function findRow(string $name): ?ProjectionRow
    {
        return $this->storeFor($name)->findRow($name);
    }

    /**
     * {@inheritDoc}
     *
     * The union of both homes, name-ordered as a single store would list. A projection lives on
     * exactly one home; a name found on BOTH is a kind-change without a state-home migration and is
     * refused loud rather than silently unioned. Single database collapses to one read.
     *
     * @throws DuplicateProjection when a name has a row on both homes
     */
    public function all(): array
    {
        $lanes = $this->lanes->distinct();

        if (count($lanes) === 1) {
            return $lanes[0]->store->all();
        }

        $rows = array_merge(...array_map(static fn ($lane): array => $lane->store->all(), $lanes));
        usort($rows, static fn (ProjectionRow $a, ProjectionRow $b): int => $a->name <=> $b->name);

        // The stale row on the old home still holds a lease, so two binaries could run the same name
        // concurrently. Detected, not assumed.
        $seen = [];
        foreach ($rows as $row) {
            if (isset($seen[$row->name])) {
                throw DuplicateProjection::splitAcrossHomes($row->name);
            }
            $seen[$row->name] = true;
        }

        return $rows;
    }

    /**
     * The home store of a name: the kind rule when the registry knows the class, the probed row
     * location for an orphan, the read-model home for a name that exists nowhere.
     *
     * @throws JsonException when a probed orphan row is malformed
     * @throws Exception on a database failure of the orphan probe
     */
    private function storeFor(string $name): ProjectionStore
    {
        if ($this->registry->has($name)) {
            return ProjectionHome::of($this->registry->get($name)) === ProjectionHome::Events
                ? $this->lanes->events->store
                : $this->lanes->readModels->store;
        }

        foreach ($this->lanes->distinct() as $lane) {
            if ($lane->store->findRow($name) !== null) {
                return $lane->store;
            }
        }

        return $this->lanes->readModels->store;
    }

    /**
     * The home store of a name for a STATUS-MARKER mutation, probed like run/reset before it routes.
     *
     * A marker settles an operator's intent against the row's truth; settling it against an empty home
     * is the same fork run and reset already refuse, so the same probe runs first, and a flip answers
     * `ProjectionHomeMismatch` loud instead of the silence. Free in single-database mode, one extra row
     * read per marker under a real split, never on a batch path.
     *
     * An orphan, registry miss, keeps the plain routing: `storeFor()` already probes by location, and
     * there is no declared kind to contradict it.
     *
     * @throws ProjectionHomeMismatch when the name's state sits on a home its current kind does not own
     * @throws JsonException when a probed row is malformed
     * @throws Exception on a database failure of the probe
     */
    private function markerStoreFor(string $name): ProjectionStore
    {
        if ($this->registry->has($name)) {
            $lane = $this->lanes->laneFor($this->registry->get($name));
            $this->lanes->assertHomedOn($name, $lane);

            return $lane->store;
        }

        return $this->storeFor($name);
    }
}
