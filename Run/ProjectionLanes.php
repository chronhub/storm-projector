<?php

declare(strict_types=1);

namespace Storm\Projector\Run;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use JsonException;
use Storm\Projector\Definition\Projection;
use Storm\Projector\Exception\ProjectionHomeMismatch;
use Storm\Projector\Store\ProjectionStore;

use function count;

/**
 * The two homes, wired once in the package services.php and selected per projection by the
 * transaction owners: the runner picks its batch-tx kit here, management its lifecycle-tx kit.
 * Every other surface speaks the routed ProjectionStore port and never sees a lane. Single
 * database: both lanes carry the SAME connection instance and `distinct()` collapses them, so
 * probing surfaces never read the one `projections` table twice.
 */
final readonly class ProjectionLanes
{
    public function __construct(
        public ProjectionLane $events,
        public ProjectionLane $readModels,
    ) {}

    /**
     * The single-database shorthand: ONE kit serves both homes, for manual and standalone wiring
     * and for the whole pre-split world, in one call.
     */
    public static function single(ProjectionStore $store, Connection $connection, ?PipelineFactory $pipeline = null): self
    {
        $lane = new ProjectionLane($store, $connection, $pipeline);

        return new self($lane, $lane);
    }

    /**
     * The lane a projection lives on, derived from its kind.
     */
    public function laneFor(Projection $projection): ProjectionLane
    {
        return ProjectionHome::of($projection) === ProjectionHome::Events ? $this->events : $this->readModels;
    }

    /**
     * Refuse to act on `$name` when its state sits on a home its declared kind does not own.
     *
     * The home comes from the KIND, so flipping a projection between link producer and read model moves
     * it between databases while its name stays put; its row, checkpoint, and lease do not follow.
     * Without this, the run creates a second row with an INDEPENDENT lease on the new home while the old
     * one stays live, so a rolling deployment has two binaries running the same logical projection, each
     * holding a lock the other cannot see. `all()` catches that afterward, when listing; this catches it
     * before it exists.
     *
     * FREE in single-database mode, the default: `distinct()` collapses to one lane, both homes are the
     * same table, and nothing can fork, so the probe is not even issued. Under a real split it costs one
     * extra row read per run or lifecycle operation, never per batch.
     *
     * @throws ProjectionHomeMismatch when a row for `$name` exists on any home other than `$declared`
     * @throws JsonException when the probed row is malformed
     * @throws Exception on a database failure of the probe
     */
    public function assertHomedOn(string $name, ProjectionLane $declared): void
    {
        $lanes = $this->distinct();

        if (count($lanes) === 1) {
            return;
        }

        foreach ($lanes as $lane) {
            if ($lane !== $declared && $lane->store->findRow($name) !== null) {
                throw ProjectionHomeMismatch::stateOnAnotherHome($name, $declared === $this->events ? ProjectionHome::Events : ProjectionHome::ReadModelStore);
            }
        }
    }

    /**
     * One lane per DISTINCT connection; single-database mode collapses to one.
     *
     * @return list<ProjectionLane>
     */
    public function distinct(): array
    {
        return $this->events->connection === $this->readModels->connection
            ? [$this->events]
            : [$this->events, $this->readModels];
    }
}
