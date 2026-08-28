<?php

declare(strict_types=1);

namespace Storm\Projector\Definition;

/**
 * The category/type selection for a `PersistentProjection`: it reads `event_store` narrowed to these
 * categories and event types, where `[]` means all. Mutually exclusive with `DerivedStreamProjection`;
 * a persistent projection implements exactly one. The selection is fixed because the checkpoint is tied
 * to it.
 *
 * Used by category-driven read models and by `LinkProjection`, whose links are always category-filtered.
 *
 * @see DerivedStreamProjection
 * @see LinkProjection
 */
interface FilteredProjection extends PersistentProjection
{
    /**
     * Stream categories to read; `[]` = all categories.
     *
     * @return list<string>
     */
    public function categories(): array;

    /**
     * Event types to read; `[]` = all types.
     *
     * @return list<string>
     */
    public function eventTypes(): array;
}
