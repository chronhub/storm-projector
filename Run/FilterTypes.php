<?php

declare(strict_types=1);

namespace Storm\Projector\Run;

use Storm\Contracts\Chronicler\EventTypeMapper;
use Storm\Projector\Definition\FilteredProjection;
use Storm\Projector\Query\QueryProjectionRunner;

/**
 * Expands declared event classes into the set of stored types to filter the event store on, mapping each
 * class to its aliases plus FQCN via the `EventTypeMapper`. Empty in yields empty out: all types, no
 * `type IN (…)` clause. Used by both `FilteredProjection::eventTypes()`, the frozen selection, and
 * `QueryProjectionRunner::resolveTypes()`, the caller-built filter.
 *
 * This is what makes `eventTypes()` alias-safe: a projection filtering by a given event class keeps
 * matching its rows after the event gains an `#[EventType]` alias, since the stored `type` becomes the
 * alias but the expanded set still contains it. Without this, adding an alias would silently starve the
 * projection.
 *
 * @see FilteredProjection::eventTypes()
 * @see QueryProjectionRunner::resolveTypes()
 */
final class FilterTypes
{
    /**
     * @param  list<string>  $classes  event classes, by FQCN, the projection declares an interest in
     * @return list<string> the stored types to filter on, deduplicated
     */
    public static function resolve(EventTypeMapper $mapper, array $classes): array
    {
        $types = [];

        foreach ($classes as $class) {
            foreach ($mapper->storedTypesOf($class) as $type) {
                // @infection-ignore-all; equivalent: a set, only the KEY is read by array_keys below, so the value is arbitrary
                $types[$type] = true;
            }
        }

        return array_keys($types);
    }
}
