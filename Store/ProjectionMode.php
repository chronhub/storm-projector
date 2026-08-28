<?php

declare(strict_types=1);

namespace Storm\Projector\Store;

use Storm\Projector\Definition\DerivedStreamProjection;
use Storm\Projector\Definition\FilteredProjection;

/**
 * The framework's built-in read modes for a `projections` row's `mode` column: `filtered` for a
 * category/type `FilteredProjection`, and `derived` for a derived-stream `DerivedStreamProjection`.
 * String-backed so the value writes straight to the column.
 *
 * `ProjectionStore::ensure()` keeps a plain `string` mode on purpose: the column is purely descriptive,
 * with no logic branching on it, and a custom projection type may write its own mode, so the store stays
 * open. This enum just fixes the framework's own modes so the runner does not hardcode magic strings.
 *
 * @see FilteredProjection
 * @see DerivedStreamProjection
 * @see ProjectionStore::ensure()
 */
enum ProjectionMode: string
{
    case Filtered = 'filtered';
    case Derived = 'derived';
}
