<?php

declare(strict_types=1);

namespace Storm\Projector\Definition;

/**
 * A projection that persists a denormalized read-model table. `apply()` writes to the table via the
 * batch connection. Its selection is a separate, mutually exclusive concern: a concrete read model also
 * implements `FilteredProjection` for a category/type slice or `DerivedStreamProjection` for a derived
 * stream.
 *
 * Persistent state means it checkpoints and resumes; the table holds the state, the checkpoint the
 * position. The output lifecycle is the `PersistentProjection` contract: `initialize()` creates the
 * table, `clear()` truncates it, `drop()` drops it. The projection owns its table, knowing its name and
 * shape, so it is its own minimal schema lifecycle with no separate manager.
 *
 * Stateless contract: a `ReadModel` MUST be stateless. `apply()` writes through to the batch `$tx` with
 * no in-memory accumulator, no business-state field on the instance. This is a contract, not a framework
 * guarantee: the read model is a singleton service, and the engine never resets its instance vars between
 * batches or runs, unlike a `QueryProjection`, which the runner `reset()`s every run. Idempotency comes
 * solely from the database, from the batch-tx rollback and the checkpoint, and holds only if the instance
 * carries no state; a stateful read model taints across batches in a `--daemon` run, masked in a CLI
 * one-shot, which is a fresh process.
 *
 * @see FilteredProjection
 * @see DerivedStreamProjection
 * @see QueryProjection
 */
interface ReadModel extends PersistentProjection {}
