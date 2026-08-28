<?php

declare(strict_types=1);

namespace Storm\Projector\Exception;

use RuntimeException;
use Storm\Projector\Run\ProjectionHome;
use Storm\Projector\Run\ProjectionLanes;

/**
 * Thrown when a projection's state is found on a database home its CURRENT kind does not declare.
 *
 * A projection's home is derived from its kind, `ProjectionHome::of()`: link producers live with
 * `event_links` on the events database, everything else with the read models. So changing a projection's
 * kind, link producer to read model or back, moves it between databases while its name stays put. Its
 * checkpoint, its lease and its output do NOT move with it.
 *
 * Left ungated, the run creates a fresh row and claims an independent lease on the new home while the old
 * row keeps its own live lease on the old one: during a rolling deployment both binaries run the same
 * logical projection at the same time, each holding a lock the other cannot see, and the read model that
 * looks caught up was rebuilt from zero over an output nobody cleared.
 *
 * Deliberately a RuntimeException, not the LogicException `DuplicateProjection` raised at registry
 * construction: this is not a coding mistake but a DEPLOYMENT state, the trace of a kind change that was
 * shipped without moving the state. It is refused, never repaired automatically; where the state should
 * end up is a decision only an operator can make.
 *
 * @see ProjectionLanes::assertHomedOn()
 * @see DuplicateProjection::splitAcrossHomes() the same fork, detected after the fact when listing
 */
final class ProjectionHomeMismatch extends RuntimeException
{
    public static function stateOnAnotherHome(string $name, ProjectionHome $declared): self
    {
        return new self(sprintf(
            'Projection "%s" declares the %s home but its state is on the other database. Its kind '
            .'(link producer vs read model) changed without a state-home migration, and running it here '
            .'would create a second row with an independent lease while the first one stays live. Move or '
            .'delete the stale row, then run again.',
            $name,
            $declared === ProjectionHome::Events ? 'events' : 'read-model store',
        ));
    }
}
