<?php

declare(strict_types=1);

namespace Storm\Projector\Testing\InMemory;

use InvalidArgumentException;
use Storm\Chronicler\Record\EventRecord;
use Storm\Projector\Run\ProjectionEventSource;
use Storm\Projector\Run\ProjectionReadWindow;
use Storm\Stream\StreamName;

/**
 * An event source over ordered fixtures with a controllable watermark, for projection tests and
 * samples that judge the run's rules, mode decisions, checkpoint targets, selection windows,
 * without a database. Its contract is deliberately REDUCED: positions come from the records
 * handed in, the watermark is whatever the test sets, and nothing here models sequence gaps,
 * advisory locks or isolation; every claim about those belongs to the PostgreSQL integration
 * suite.
 *
 * The window's `after`, `max` and `limit` are honored exactly; the selection lists are NOT
 * interpreted, since fixtures are already the records the test means to feed.
 */
final class InMemoryProjectionEventSource implements ProjectionEventSource
{
    /** @var list<EventRecord> */
    private array $records = [];

    private int $watermark = 0;

    /**
     * @param  list<EventRecord>  $records  in ascending `sequence_no` order
     */
    public function __construct(
        array $records = [],
        int $watermark = 0,
    ) {
        $this->append($records);
        $this->advanceWatermarkTo($watermark);
    }

    public function watermark(?StreamName $sourceStream): int
    {
        return $this->watermark;
    }

    public function read(ProjectionReadWindow $window): array
    {
        $page = [];

        foreach ($this->records as $record) {
            // @infection-ignore-all; equivalent: the contracted decimal ordinal compares the same as numeric string or int
            $position = $record->position->toOrdinal();
            if ($position <= $window->after || $position > $window->max) {
                continue;
            }

            $page[] = $record;
            if (count($page) === $window->limit) {
                break;
            }
        }

        return $page;
    }

    /**
     * Moves the watermark, the test's own frontier control: raise it to make appended fixtures
     * readable, hold it to model a producer whose tail is still undecided.
     */
    public function advanceWatermarkTo(int $watermark): void
    {
        if ($watermark < $this->watermark) {
            throw new InvalidArgumentException('The projection fixture watermark cannot move backwards.');
        }

        $this->watermark = $watermark;
    }

    /**
     * @param  list<EventRecord>  $records  appended in order after the existing fixtures
     */
    public function append(array $records): void
    {
        $candidate = [...$this->records, ...$records];
        // @infection-ignore-all; EventRecord positions are positive ordinals, so 0 and -1 are the
        // same lower sentinel for every value the contracted input can carry
        $previous = 0;

        foreach ($candidate as $record) {
            $position = $record->position->toOrdinal();

            if ($position <= $previous) {
                throw new InvalidArgumentException('Projection fixtures must be strictly ordered by sequence position.');
            }

            $previous = $position;
        }

        // Commit only after the whole candidate has passed, so one malformed tail cannot partly
        // mutate a fixture that a test catches and keeps using.
        $this->records = $candidate;
    }
}
