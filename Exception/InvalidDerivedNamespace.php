<?php

declare(strict_types=1);

namespace Storm\Projector\Exception;

use LogicException;

/**
 * A derived link output namespace is malformed or collides with another producer's. A derived
 * target, a `LinkProjection`'s fixed `targetStream()` or a `FanOutLinkProjection`'s `targetPrefix()`,
 * is an OWNED namespace: exactly one producer writes it, and lifecycle cleanup via
 * `deleteLinksByPrefix` trusts that ownership. Two producers sharing a target, an overlapping prefix, a
 * fixed target sitting under a prefix, or an empty prefix would let one projection's reset corrupt or
 * erase another's output. A wiring/declaration bug to fix, not a runtime condition; hence a
 * `LogicException`, raised at registry construction at startup or, for the dynamic fan-out case, at the
 * write seam.
 */
final class InvalidDerivedNamespace extends LogicException
{
    public static function duplicateFixedTarget(string $target, string $first, string $second): self
    {
        return new self(sprintf(
            'Two link projections target the same derived stream "%s": %s and %s. A derived target has exactly one producer — the max(target_position)+1 allocation and the reset/delete both assume it.',
            $target,
            $first,
            $second,
        ));
    }

    public static function emptyPrefix(string $projection): self
    {
        return new self(sprintf(
            'The fan-out link projection %s declares an empty targetPrefix(): starts_with(x, "") matches every row, so its reset/delete would erase ALL derived streams. Declare a non-empty, owned prefix.',
            $projection,
        ));
    }

    public static function overlappingPrefixes(string $a, string $prefixA, string $b, string $prefixB): self
    {
        return new self(sprintf(
            'Fan-out prefixes overlap: %s owns "%s" and %s owns "%s" — one is a prefix of the other, so resetting one would delete the other\'s links. Prefixes must be disjoint.',
            $a,
            $prefixA,
            $b,
            $prefixB,
        ));
    }

    public static function fixedTargetUnderPrefix(string $linkProjection, string $target, string $fanOut, string $prefix): self
    {
        return new self(sprintf(
            'The fixed target "%s" of %s sits under the fan-out prefix "%s" of %s — resetting the fan-out would delete this fixed producer\'s links. A fixed target must not be covered by any prefix.',
            $target,
            $linkProjection,
            $prefix,
            $fanOut,
        ));
    }

    public static function targetOutsidePrefix(string $fanOut, string $target, string $prefix): self
    {
        return new self(sprintf(
            'The fan-out %s produced target "%s" outside its declared prefix "%s" — its reset (which deletes by prefix) would then leak this output. targetFor() must stay within targetPrefix().',
            $fanOut,
            $target,
            $prefix,
        ));
    }

    public static function emptyPrefixWouldEraseEverything(): self
    {
        return new self(
            'Refusing a prefix delete with an empty prefix: starts_with(target_stream, "") matches every row and would erase ALL derived streams. This is the last-line guard; the registry rejects an empty targetPrefix() at startup.',
        );
    }
}
