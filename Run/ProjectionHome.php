<?php

declare(strict_types=1);

namespace Storm\Projector\Run;

use Storm\Projector\Definition\FanOutLinkProjection;
use Storm\Projector\Definition\LinkProjection;
use Storm\Projector\Definition\Projection;

/**
 * WHERE a projection lives: the connection its checkpoint AND its writes share one tx on.
 *
 * The invariant behind the read-model store split is per projection, never global: ITS checkpoint
 * commits with ITS writes. Link producers write `event_links`, which must stay WITH `event_store`
 * since the derived-stream read joins them, so they home on the EVENTS connection, exactly the
 * single-database behavior, pinned. Every other persistent projection writes read models and homes
 * on the read-model store connection, which IS the default connection until the app opts in: both
 * homes collapse to one, byte-identical.
 *
 * Derived from the KIND, deliberately zero-config: the projection's interfaces already say what it
 * writes. A DerivedStream CONSUMER homes store-side because it writes an rm table; its join-read
 * runs through the StreamReader on the events connection either way.
 */
enum ProjectionHome
{
    case Events;
    case ReadModelStore;

    public static function of(Projection $projection): self
    {
        return $projection instanceof LinkProjection || $projection instanceof FanOutLinkProjection
            ? self::Events
            : self::ReadModelStore;
    }
}
