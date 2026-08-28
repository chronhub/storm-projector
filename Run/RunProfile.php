<?php

declare(strict_types=1);

namespace Storm\Projector\Run;

use Storm\Contracts\Chronicler\EventTypeMapper;
use Storm\Projector\Definition\DerivedStreamProjection;
use Storm\Projector\Definition\FilteredProjection;
use Storm\Projector\Definition\PersistentProjection;
use Storm\Projector\Definition\Projection;
use Storm\Projector\Exception\UnsupportedProjection;
use Storm\Stream\StreamName;

/**
 * Immutable per-run config: the projection + its resolved topology + the RunOptions. Built
 * once at run start; the stages read it via RunState::$profile.
 *
 * @see RunState::$profile
 */
final readonly class RunProfile
{
    /**
     * `$types` is the alias-expanded set the read SQL filters on; `$eventClasses` is the declared class
     * list it was expanded from, the dev's intent, which the topology gate compares against the stored
     * row, since an added `#[EventType]` alias moves `$types` but not `$eventClasses`.
     *
     * @param  list<string>  $categories
     * @param  list<string>  $types
     * @param  list<string>  $eventClasses
     */
    public function __construct(
        public Projection $projection,
        public string $name,
        public array $categories,
        public array $types,
        public array $eventClasses,
        public ?StreamName $sourceStream,
        public RunOptions $options,
    ) {}

    /**
     * @throws UnsupportedProjection when the projection declares neither or both selections; it must
     *                               implement exactly one of FilteredProjection or DerivedStreamProjection
     */
    public static function for(
        PersistentProjection $projection,
        RunOptions $options,
        // REQUIRED, no identity default: the mapper expands declared classes into their stored
        // types, so an omitted argument silently selects different rows than production
        EventTypeMapper $eventTypeMapper,
    ): self {
        $categories = [];
        $types = [];
        $eventClasses = [];

        if ($projection instanceof FilteredProjection) {
            $categories = $projection->categories();
            $eventClasses = $projection->eventTypes();
            $types = FilterTypes::resolve($eventTypeMapper, $eventClasses);
        }

        $sourceStream = $projection instanceof DerivedStreamProjection ? $projection->sourceStream() : null;

        // exactly one selection: a category/type FilteredProjection XOR a derived stream
        if (($projection instanceof FilteredProjection) === ($sourceStream !== null)) {
            throw UnsupportedProjection::invalidSelection($projection->name());
        }

        return new self(
            $projection,
            $projection->name(),
            $categories,
            $types,
            $eventClasses,
            $sourceStream,
            $options,
        );
    }
}
