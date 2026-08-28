<?php

declare(strict_types=1);

namespace Storm\Projector\Exception;

use RuntimeException;
use Storm\EventLinks\DerivedStreamRevision;
use Storm\Projector\Definition\PersistentProjection;
use Storm\Projector\Run\TopologyDrift;
use Storm\Stream\StreamName;

/**
 * Thrown when a run is refused because the read model on disk no longer matches the code that would
 * advance it:
 *
 * - Generation mismatch: the declared generation differs from the built-under one, so the definition
 *   changed and the data is stale. Rebuild via `storm:projection:reset` plus replay, or pass
 *   `--allow-stale` to knowingly run over the old data.
 *
 * - Topology drift: the selection, mode, categories, event classes or source stream, changed while the
 *   checkpoint was kept. "Caught up to N" only has meaning against the perimeter it was read under, so
 *   advancing would leave silent holes below the checkpoint. Not bypassable: serving stale data is an
 *   informed choice, advancing a new perimeter over an old checkpoint is forward corruption.
 *
 * - Source rebuilt: the consumed derived stream was destructively rebuilt under a kept checkpoint. The
 *   code did not change here, the DATA did; the two above compare declarations, this one compares the
 *   stream's membership revision. Not bypassable, for the same reason as drift.
 *
 * @see PersistentProjection::generation()
 * @see TopologyDrift
 * @see DerivedStreamRevision
 */
final class ProjectionOutOfDate extends RuntimeException
{
    public static function generationMismatch(string $name, int $declared, int $stored): self
    {
        return new self(sprintf(
            'Projection "%s" is out of date: code generation %d, read model built under generation %d. '
            .'Rebuild with storm:projection:reset, or pass --allow-stale to run anyway.',
            $name,
            $declared,
            $stored,
        ));
    }

    /**
     * The producer rebuilt the consumed stream. Names the stream and both revisions, because the operator
     * facing this did nothing to the consumer: the cause is somewhere else entirely, and a refusal that
     * does not point there reads as a bug in the consumer.
     */
    public static function sourceRebuilt(string $name, StreamName $source, int $stored, int $live): self
    {
        return new self(sprintf(
            'Projection "%s" is out of date: its source stream "%s" was rebuilt (folded under revision %d, '
            .'now revision %d). A rebuild can link a source position BELOW the kept checkpoint, which would '
            .'stay invisible forever, so the checkpoint no longer means "everything linked is folded". '
            .'Rebuild the consumer with storm:projection:reset.',
            $name,
            $source->toString(),
            $stored,
            $live,
        ));
    }

    /**
     * @param  list<string>  $axes  the drifted axes, as rendered by TopologyDrift::between()
     */
    public static function topologyDrift(string $name, array $axes): self
    {
        return new self(sprintf(
            'Projection "%s" is out of date: its selection changed while the checkpoint was kept (%s). '
            .'The checkpoint only has meaning against the perimeter it was built under: bump generation() '
            .'and rebuild with storm:projection:reset, or revert the selection.',
            $name,
            implode('; ', $axes),
        ));
    }
}
