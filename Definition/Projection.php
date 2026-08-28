<?php

declare(strict_types=1);

namespace Storm\Projector\Definition;

use Doctrine\DBAL\Connection;
use Storm\Chronicler\Record\EventRecord;
use Throwable;

/**
 * Base contract for a projection: a named consumer of the event stream that folds events via
 * `apply()`. The selection, which categories and event types it reads, is not here; it lives on
 * `PersistentProjection`, where a checkpoint pins it, and is passed at run for a checkpoint-less
 * one-shot `QueryProjection`. So the base carries only what every projection shares.
 *
 * Lives in the package rather than Contracts because it names concrete types, `EventRecord` and the
 * DBAL `Connection`. A concrete projection implements one of the three sub-types, `ReadModel`,
 * `LinkProjection` or `QueryProjection`, and is discovered via `AsProjection`.
 *
 * @see PersistentProjection
 * @see QueryProjection
 * @see ReadModel
 * @see LinkProjection
 * @see AsProjection
 */
interface Projection
{
    /**
     * The unique name: the registry key and the checkpoint key.
     */
    public function name(): string;

    /**
     * Apply one event within the batch transaction. Returns false for a duplicate or no-op, e.g. a
     * link already emitted. `$tx` is the batch connection for handlers that write; a `QueryProjection`
     * ignores it, folding in memory.
     *
     * Every write MUST ride `$tx` and nothing else: this is the module's largest UNENFORCED
     * contract. A handler writing through its own injected connection compiles, passes every gate,
     * and runs, but its writes then commit independently of the checkpoint, and a crash between
     * the two produces exactly the silent double-apply the one-batch-one-transaction design exists
     * to prevent. No runtime guard can tell the two connections apart from here; the contract is
     * this sentence.
     *
     * @throws Throwable any handler failure, such as a DBAL write; propagated to the runner, which
     *                   rolls back the batch transaction and marks the projection failed
     */
    public function apply(EventRecord $event, Connection $tx): bool;
}
