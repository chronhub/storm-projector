<?php

declare(strict_types=1);

namespace Storm\Projector\Store;

use JsonException;
use Storm\Stream\Exception\InvalidStreamException;
use Storm\Stream\StreamName;

/**
 * A read-back snapshot of a `projections` row: what `ProjectionStore::findRow()` returns for inspection,
 * the `storm:projection:status` command, and for the run engine to read topology and state. A plain data
 * carrier; the store owns all mutations.
 *
 * @see ProjectionStore::findRow()
 */
final readonly class ProjectionRow
{
    /**
     * @param  list<string>  $categories
     * @param  list<string>  $eventClasses
     */
    public function __construct(
        public string $name,
        public ProjectionStatus $status,
        public int $lastPosition,
        public string $mode,
        public array $categories,
        public array $eventClasses,
        public ?StreamName $sourceStream,
        public int $sourceRevision,
        public ?StreamName $targetStream,
        public ?string $targetPrefix,
        public ?string $leaseOwner,
        public ?string $leaseUntil,
        public ?string $lastHeartbeatAt,
        public ?string $pauseUntil,
        public int $generation,
        public ?string $failedAt = null,
        public ?string $errorMessage = null,
        public ?string $errorClass = null,
    ) {}

    /**
     * Build from a raw `projections` row, the store's `COLUMNS` select: the one place the column-to-field
     * binding lives, by name, so it cannot drift the way a positional 18-arg call would.
     *
     * @param  array<string, mixed>  $row
     *
     * @throws JsonException when the `categories` / `event_classes` JSON is malformed
     * @throws InvalidStreamException when the stored `source_stream` / `target_stream` does not parse as a StreamName
     */
    public static function fromRow(array $row): self
    {
        $str = static fn (mixed $value): ?string => $value === null ? null : (string) $value;

        /** @var list<string> $categories */
        $categories = json_decode((string) $row['categories'], true, flags: JSON_THROW_ON_ERROR);

        /** @var list<string> $eventClasses */
        $eventClasses = json_decode((string) $row['event_classes'], true, flags: JSON_THROW_ON_ERROR);

        return new self(
            name: (string) $row['name'],
            status: ProjectionStatus::from((string) $row['status']),
            lastPosition: (int) $row['last_position'],
            mode: (string) $row['mode'],
            categories: $categories,
            eventClasses: $eventClasses,
            sourceStream: $row['source_stream'] === null ? null : new StreamName((string) $row['source_stream']),
            sourceRevision: (int) $row['source_revision'],
            targetStream: $row['target_stream'] === null ? null : new StreamName((string) $row['target_stream']),
            targetPrefix: $str($row['target_prefix']),
            leaseOwner: $str($row['lease_owner']),
            leaseUntil: $str($row['lease_until']),
            lastHeartbeatAt: $str($row['last_heartbeat_at']),
            pauseUntil: $str($row['pause_until']),
            generation: (int) $row['generation'],
            failedAt: $str($row['failed_at']),
            errorMessage: $str($row['error_message']),
            errorClass: $str($row['error_class']),
        );
    }

    /**
     * The machine shape, snake_case on the wire: what `storm:projection:status --json` serves, and what
     * the ops HTTP twin renders from the same row.
     *
     * The error travels WHOLE here, class and message, where the table shortens the class and cuts the
     * message at sixty characters: the truncation is honest on a terminal and useless to whoever has to
     * read the cause. The full backtrace stays in telemetry either way.
     *
     * @return array{name: string, status: string, last_position: int, mode: string, categories: list<string>, event_classes: list<string>, source_stream: string|null, source_revision: int, target_stream: string|null, target_prefix: string|null, lease_owner: string|null, lease_until: string|null, last_heartbeat_at: string|null, pause_until: string|null, generation: int, failed_at: string|null, error_class: string|null, error_message: string|null}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'status' => $this->status->value,
            'last_position' => $this->lastPosition,
            'mode' => $this->mode,
            'categories' => $this->categories,
            'event_classes' => $this->eventClasses,
            'source_stream' => $this->sourceStream?->toString(),
            'source_revision' => $this->sourceRevision,
            'target_stream' => $this->targetStream?->toString(),
            'target_prefix' => $this->targetPrefix,
            'lease_owner' => $this->leaseOwner,
            'lease_until' => $this->leaseUntil,
            'last_heartbeat_at' => $this->lastHeartbeatAt,
            'pause_until' => $this->pauseUntil,
            'generation' => $this->generation,
            'failed_at' => $this->failedAt,
            'error_class' => $this->errorClass,
            'error_message' => $this->errorMessage,
        ];
    }
}
