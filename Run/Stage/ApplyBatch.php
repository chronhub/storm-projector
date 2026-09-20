<?php

declare(strict_types=1);

namespace Storm\Projector\Run\Stage;

use Closure;
use Doctrine\DBAL\Connection;
use Storm\Projector\Definition\BatchProjection;
use Storm\Projector\Run\RunState;
use Storm\Projector\Run\Stage;
use Throwable;

/**
 * Applies each record to the projection within the batch transaction. The same DBAL connection that
 * the runner wrapped in `transactional()` is handed to `apply()` so the handler's writes commit
 * atomically with the checkpoint advance. A `BatchProjection` hears the batch once through its
 * batch verb instead, and reports the same count.
 */
final readonly class ApplyBatch implements Stage
{
    public function __construct(
        private Connection $connection,
    ) {}

    /**
     * @throws Throwable propagated from the projection's `apply()`, a handler or DBAL write failure
     */
    public function __invoke(RunState $state, Closure $next): int
    {
        $projection = $state->profile->projection;

        if ($projection instanceof BatchProjection && $state->records !== []) {
            // the batch verb hears the whole batch once; it reports what the per-record loop counts
            $state->applied += $projection->applyBatch($state->records, $this->connection);
            $state->lastApplied = $state->records[array_key_last($state->records)]->position->toOrdinal();

            return $next($state);
        }

        foreach ($state->records as $record) {
            if ($projection->apply($record, $this->connection)) {
                $state->applied++;
            }

            $state->lastApplied = $record->position->toOrdinal();
        }

        return $next($state);
    }
}
