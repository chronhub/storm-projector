<?php

declare(strict_types=1);

namespace Storm\Projector\Run;

use Storm\Projector\Store\ProjectionRow;
use Storm\Stream\StreamName;

use function array_unique;
use function array_values;
use function implode;
use function sort;
use function sprintf;

/**
 * Compares the read topology a checkpoint was built under, the stored row, with the one the code
 * declares, the run profile: mode, categories, declared event classes, source stream, AND the derived
 * output namespace, whether fixed `target_stream` or fan-out `target_prefix`. The selection axes compare
 * as sets, so declaration order is not topology.
 *
 * Classes, not alias-resolved types, are the anchor on purpose: the dev's intent is the class list and
 * the expansion is derived, so gaining an `#[EventType]` alias never reads as drift, the `FilterTypes`
 * promise, while adding or removing a class does.
 *
 * Output IS checkpoint-bound: a producer's target/prefix names WHERE its checkpoint's work has landed,
 * so renaming `targetStream()` over a non-zero checkpoint would send only the events past that
 * checkpoint to the new stream, a hollow output missing its history, and leave the old stream orphaned,
 * since `reset` clears the NEW target, not the old row's. The runner refuses this over a non-zero
 * checkpoint exactly like a selection drift; the sanctioned path is bump + reset, which zeroes the
 * checkpoint, or the explicit `retire`/`--orphan-stream` decommission.
 *
 * @see ProjectionRunner the run-start gate consuming this
 * @see FilterTypes
 */
final class TopologyDrift
{
    /**
     * @param  ?StreamName  $declaredTarget  the LinkProjection's fixed target, or null
     * @param  ?string  $declaredPrefix  the FanOutLinkProjection's prefix, or null
     * @return list<string> one human-readable line per drifted axis, `stored` versus `declared`; empty
     *                      when the topologies match
     */
    public static function between(ProjectionRow $stored, string $mode, RunProfile $declared, ?StreamName $declaredTarget = null, ?string $declaredPrefix = null): array
    {
        $axes = [];

        if ($stored->mode !== $mode) {
            $axes[] = sprintf('mode: stored "%s", declared "%s"', $stored->mode, $mode);
        }

        if (self::asSet($stored->categories) !== self::asSet($declared->categories)) {
            $axes[] = sprintf('categories: stored [%s], declared [%s]', self::render($stored->categories), self::render($declared->categories));
        }

        if (self::asSet($stored->eventClasses) !== self::asSet($declared->eventClasses)) {
            $axes[] = sprintf('event classes: stored [%s], declared [%s]', self::render($stored->eventClasses), self::render($declared->eventClasses));
        }

        if (self::streamOf($stored->sourceStream) !== self::streamOf($declared->sourceStream)) {
            $axes[] = sprintf('source stream: stored %s, declared %s', self::renderStream($stored->sourceStream), self::renderStream($declared->sourceStream));
        }

        if (self::streamOf($stored->targetStream) !== self::streamOf($declaredTarget)) {
            $axes[] = sprintf('target stream: stored %s, declared %s', self::renderStream($stored->targetStream), self::renderStream($declaredTarget));
        }

        if ($stored->targetPrefix !== $declaredPrefix) {
            $axes[] = sprintf('target prefix: stored %s, declared %s', self::renderPrefix($stored->targetPrefix), self::renderPrefix($declaredPrefix));
        }

        return $axes;
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private static function asSet(array $values): array
    {
        // @infection-ignore-all; equivalent: sort below reindexes, the values wrap only tidies the interim shape
        $unique = array_values(array_unique($values));
        sort($unique);

        return $unique;
    }

    /**
     * @param  list<string>  $values
     */
    private static function render(array $values): string
    {
        return implode(', ', self::asSet($values));
    }

    private static function streamOf(?StreamName $stream): ?string
    {
        return $stream?->toString();
    }

    private static function renderStream(?StreamName $stream): string
    {
        return $stream === null ? 'none' : sprintf('"%s"', $stream->toString());
    }

    private static function renderPrefix(?string $prefix): string
    {
        return $prefix === null ? 'none' : sprintf('"%s"', $prefix);
    }
}
