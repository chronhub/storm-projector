<?php

declare(strict_types=1);

namespace Storm\Projector\Link;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\ParameterType;
use Storm\Projector\Definition\FanOutLinkProjection;
use Storm\Projector\Definition\LinkProjection;
use Storm\Projector\Exception\InvalidDerivedNamespace;
use Storm\Stream\StreamName;

/**
 * Links a source event into a derived/target stream, the one thing a `LinkProjection`'s `apply()` does.
 * Stateless; the batch connection `$tx` is passed in so the link commits atomically with the projector's
 * checkpoint advance.
 *
 * The next `target_position` is computed inline as `max + 1` within the batch transaction, which sees the
 * batch's own prior inserts, so positions are dense and in `sequence_no` order. `ON CONFLICT
 * (target_stream, source_sequence) DO NOTHING` makes a re-link a no-op, the idempotency backstop.
 *
 * The `max + 1` is race-free for two workers of the SAME projection because the acquire stage's
 * `FOR UPDATE` on the shared checkpoint row serializes them inside the batch transaction, lease or
 * no lease; the stronger guard, not the lease. The residual is two DIFFERENT projections targeting
 * one stream: the registry refuses that within a process, but not across two deployments running
 * different registries, a rolling deploy that renames a projection but keeps its target. There the
 * `UNIQUE (target_stream, target_position)` is the crash-consistency backstop, so the race surfaces
 * LOUD as a batch failure rather than silently lost-updating. A future multi-producer derived
 * stream would need a per-target sequence/identity, not `max + 1`.
 *
 * @see LinkProjection
 */
final readonly class EventLinkWriter
{
    /**
     * @return bool true if a new link was inserted, false if it already existed
     *
     * @throws Exception on a DBAL failure of the link insert
     */
    public function link(Connection $tx, int $sourceSequence, StreamName $targetStream): bool
    {
        $affected = $tx->executeStatement(
            /** @lang PostgreSQL */
            <<<'SQL'
                INSERT INTO event_links (target_stream, target_position, source_sequence)
                VALUES (
                    :target,
                    (SELECT COALESCE(max(target_position), 0) + 1 FROM event_links WHERE target_stream = :target),
                    :source
                )
                ON CONFLICT (target_stream, source_sequence) DO NOTHING
                SQL,
            ['target' => $targetStream->toString(), 'source' => $sourceSequence],
            ['source' => ParameterType::INTEGER],
        );

        return (int) $affected > 0;
    }

    /**
     * Remove every link of a target stream, used by reset/delete of a LinkProjection. Pass the
     * batch/operation connection so it is part of the surrounding transaction.
     *
     * Bumps the stream's membership revision in the SAME statement, via a data-modifying CTE: a
     * consumer's checkpoint claims "folded everything linked up to here", which stops being true the
     * moment the links below it are rebuilt, and the bump is what lets the consumer's run gate see it.
     * Deleting and bumping cannot be allowed to drift apart, hence one statement rather than two.
     *
     * The bump is driven by what was ACTUALLY deleted, `SELECT ... FROM deleted`, so clearing an
     * already-empty stream bumps nothing: with no links there is nothing a consumer could be sitting
     * above, and a false bump would refuse it for no reason. `DISTINCT` keeps the UPSERT to one row per
     * target, which `ON CONFLICT DO UPDATE` requires.
     *
     * @throws Exception on a DBAL failure of the delete
     *
     * @see LinkProjection
     */
    public function deleteLinks(Connection $tx, StreamName $targetStream): void
    {
        $tx->executeStatement(
            /** @lang PostgreSQL */
            <<<'SQL'
                WITH deleted AS (
                    DELETE FROM event_links WHERE target_stream = :target RETURNING target_stream
                )
                INSERT INTO event_link_streams (target_stream, revision)
                SELECT DISTINCT target_stream, 1 FROM deleted
                ON CONFLICT (target_stream) DO UPDATE SET
                    revision = event_link_streams.revision + 1,
                    rebuilt_at = clock_timestamp()
                SQL,
            ['target' => $targetStream->toString()],
        );
    }

    /**
     * Remove every link whose target stream starts with `$prefix`, for a FanOutLinkProjection's
     * clear/drop, and forget of an orphaned fan-out row, which produces many targets under one shared
     * prefix, an app naming convention such as `et-`, rather than a single one. Prefix-exact via
     * `starts_with`, no LIKE wildcards. Pass the batch/operation connection so it joins the surrounding
     * transaction.
     *
     * Bumps the revision of every target the delete actually emptied, one row each, exactly as
     * `deleteLinks()` does for a fixed target; a fan-out's consumers each fold ONE of those targets, so
     * the revision has to be per-target and not per-prefix.
     *
     * @throws InvalidDerivedNamespace when `$prefix` is empty or blank; `starts_with(x, '')` matches
     *                                 every row, so the delete would erase ALL derived streams
     * @throws Exception on a DBAL failure of the delete
     *
     * @see FanOutLinkProjection
     */
    public function deleteLinksByPrefix(Connection $tx, string $prefix): void
    {
        if (trim($prefix) === '') {
            // starts_with(x, '') is true for EVERY row; an empty prefix would erase ALL derived
            // streams, every fan-out's and every fixed target's, in one lifecycle op. Refused loud;
            // the registry gate rejects an empty targetPrefix() at startup, this is the last-line guard.
            throw InvalidDerivedNamespace::emptyPrefixWouldEraseEverything();
        }

        $tx->executeStatement(
            /** @lang PostgreSQL */
            <<<'SQL'
                WITH deleted AS (
                    DELETE FROM event_links WHERE starts_with(target_stream, :prefix) RETURNING target_stream
                )
                INSERT INTO event_link_streams (target_stream, revision)
                SELECT DISTINCT target_stream, 1 FROM deleted
                ON CONFLICT (target_stream) DO UPDATE SET
                    revision = event_link_streams.revision + 1,
                    rebuilt_at = clock_timestamp()
                SQL,
            ['prefix' => $prefix],
        );
    }
}
