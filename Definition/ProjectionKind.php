<?php

declare(strict_types=1);

namespace Storm\Projector\Definition;

/**
 * What kind of projection a class is, named once for every channel that names it.
 *
 * The kinds are resolved by parentage and the ORDER matters: a fan-out is a sibling of `LinkProjection`
 * rather than its subtype, but `GroupedReadModel` IS a `ReadModel`, so the narrower arm has to come
 * first or the grouping key it is named for disappears.
 *
 * One resolver because two of them drift: a listing and an introspection document that answer the same
 * question in different words send a script filtering on what one showed to find nothing in the other,
 * silently.
 */
enum ProjectionKind: string
{
    case FanOutLink = 'fan-out-link';

    case Link = 'link';

    case GroupedReadModel = 'grouped-read-model';

    case ReadModel = 'read-model';

    case Query = 'query';

    /**
     * The neutral answer for a projection outside the known kinds: an app may implement the base
     * contracts directly, and a listing that refused to name it would be worse than a plain word.
     */
    case Projection = 'projection';

    public static function of(Projection $projection): self
    {
        return match (true) {
            $projection instanceof FanOutLinkProjection => self::FanOutLink,
            $projection instanceof LinkProjection => self::Link,
            $projection instanceof GroupedReadModel => self::GroupedReadModel,
            $projection instanceof ReadModel => self::ReadModel,
            $projection instanceof QueryProjection => self::Query,
            default => self::Projection,
        };
    }
}
