<?php

declare(strict_types=1);

namespace Storm\Projector\Definition;

use Attribute;
use Storm\Projector\Registry\ProjectionRegistry;

/**
 * Marks a `Projection` service for discovery: the bundle auto-tags it `storm.projection`, so the
 * `ProjectionRegistry` collects it. The tagged services are the source of truth, with no separate
 * registration list.
 *
 * Parameterless on purpose, since the topology is read from the projection itself as the single source
 * of truth: the name from `name()`, the filters from `categories()`/`eventTypes()`, the target from
 * `LinkProjection::targetStream()`, and the mode from the selection it declares, `filtered` for a
 * category/type `FilteredProjection` and `derived` for a `DerivedStreamProjection`. The attribute is only
 * the discovery marker.
 *
 * @see Projection
 * @see ProjectionRegistry
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class AsProjection {}
