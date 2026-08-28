<?php

declare(strict_types=1);

namespace Storm\Projector\Store;

/**
 * The repository port over the `projections` row, the composition of its three facets: the run
 * path's checkpoint-and-lease verbs, the operator's lifecycle transitions, and the read-only
 * catalog. A module-local port like the Chronicler's stream ports: its vocabulary is the
 * projector's own, and every surface that reads or mutates projection rows speaks it, never a
 * concrete store.
 *
 * Lease model is soft-claim: a worker takes a projection whose lease is free, expired, or already
 * its own; it renews to keep it, releases on exit. Status transitions are explicit and
 * compare-and-set, via `pause` / `resume` / `requestStop` / `retry` and `releaseLease`; claiming a
 * lease does NOT change status, the run stages own that.
 *
 * A consumer names the facet it stands on; this union is for the one place that owns the row
 * whole, the Dbal implementation, and the lane that hands it to both the runner and the
 * in-transaction lifecycle operations.
 */
interface ProjectionStore extends ProjectionCatalog, ProjectionLifecycleStore, ProjectionRunStore {}
