<?php

declare(strict_types=1);

namespace Storm\Projector\Schema;

use Storm\Projector\Store\ProjectionStatus;

/**
 * Raw PostgreSQL DDL for `projections`: one row per projection, holding state, checkpoint and lease
 * together. The checkpoint is part of the projection's state, and one row means a single `FOR UPDATE`
 * lock per batch. Metrics are deferred to telemetry.
 *
 * - `last_position`: the checkpoint, the global `sequence_no` up to which events are applied, `0` when
 *   fresh. Advanced atomically with the batch under the row lock.
 *
 * - `status`: the ProjectionStatus, idle/running/paused/stopping/failed.
 *
 * - `lease_owner`/`lease_until`/`last_heartbeat_at`: the daemon lease, soft-claim over free, expired or
 *   same owner. `generation` is the build generation the read model was folded under, realigned by
 *   `reset` to the rebuilt definition's. `pause_until` holds a timed pause.
 *
 * - `failed_at`/`error_message`/`error_class`: set when a run hits an unrecoverable error, status
 *   `failed`, the what and when for quick triage in `storm:projection:status`. The full backtrace goes to
 *   telemetry/logs, not the row, which is operational state, not a log sink. Cleared on `reset`. All are
 *   nullable.
 *
 * - `mode`/`categories`/`event_classes`/`source_stream`: the read topology the checkpoint was built
 *   under, declared at `ensure`. These are the selection axes of a FilteredProjection, where
 *   `event_classes` holds the declared event classes, not the alias-resolved stored types, the dev's
 *   intent, so an added `#[EventType]` alias is not a topology change; or the source stream of a
 *   DerivedStreamProjection. The run-start topology gate compares them against the code and refuses a
 *   drifted selection over a kept checkpoint.
 *
 * - `target_stream`/`target_prefix`: a stream producer's output, recorded so `forget` can clean up
 *   without the class: the fixed target stream of a LinkProjection, or the declared prefix its many
 *   targets live under for a FanOutLinkProjection; mutually exclusive, both NULL for a read model. Output
 *   IS checkpoint-bound, `TopologyDrift` gating a rename over a non-zero checkpoint, and the stored value
 *   is the one `reset` cleans, since it names where this checkpoint's work actually landed.
 *
 * - `source_revision`: for a DerivedStreamProjection, the membership revision its source stream carried
 *   when this checkpoint started, from `event_link_streams`. A checkpoint over a derived stream claims a
 *   SET was folded, so a destructive rebuild of the producer invalidates it without moving any position;
 *   the run gate compares this against the live revision and refuses on a mismatch. Bound once, against a
 *   checkpoint of 0, the moment where there is nothing yet to invalidate. NULL-free, defaulting to 0, the
 *   never-rebuilt value.
 *
 * No secondary indexes: the table is tiny, one row per projection, so the PK suffices.
 *
 * @see ProjectionStatus
 */
final class ProjectionSchema
{
    /**
     * This table follows `storm.connections.read_model_store`: the checkpoint must commit in the SAME tx
     * as the read-model writes, the exactly-once mechanism, so it lives wherever they live. Read by
     * `bin/dump-ddl.php` to section the schema output; absent means the events side.
     */
    public const string CONNECTION_SIDE = 'read_model_store';

    /**
     * @return list<string>
     */
    public static function up(): array
    {
        return [
            /** @lang PostgreSQL */
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS projections (
                    name              text   NOT NULL,
                    status            text   NOT NULL DEFAULT 'idle',
                    last_position     bigint NOT NULL DEFAULT 0,
                    mode              text   NOT NULL,
                    categories        jsonb  NOT NULL DEFAULT '[]'::jsonb,
                    event_classes     jsonb  NOT NULL DEFAULT '[]'::jsonb,
                    source_stream     text,
                    source_revision   bigint NOT NULL DEFAULT 0,
                    target_stream     text,
                    target_prefix     text,
                    lease_owner       text,
                    lease_until       timestamptz(6),
                    last_heartbeat_at timestamptz(6),
                    pause_until       timestamptz(6),
                    failed_at         timestamptz(6),
                    error_message     text,
                    error_class       text,
                    generation        bigint NOT NULL DEFAULT 0,
                    created_at        timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                    updated_at        timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                    CONSTRAINT projections_pk PRIMARY KEY (name)
                )
                SQL,
        ];
    }

    /**
     * @return list<string>
     */
    public static function down(): array
    {
        return [
            /** @lang PostgreSQL */
            'DROP TABLE IF EXISTS projections',
        ];
    }
}
