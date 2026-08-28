<?php

declare(strict_types=1);

namespace Storm\Projector\Registry;

use Storm\Projector\Definition\AsProjection;
use Storm\Projector\Definition\FanOutLinkProjection;
use Storm\Projector\Definition\LinkProjection;
use Storm\Projector\Definition\PersistentProjection;
use Storm\Projector\Definition\Projection;
use Storm\Projector\Exception\DuplicateProjection;
use Storm\Projector\Exception\InvalidDerivedNamespace;
use Storm\Projector\Exception\UnknownProjection;
use Storm\Projector\Exception\UnsupportedProjection;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

use function str_starts_with;

/**
 * Name-to-Projection lookup, built from every service tagged `storm.projection` via `AsProjection` and
 * the bundle's attribute autoconfiguration. The tagged projections are the source of truth, with no
 * separate registration list.
 *
 * Indexes by `name()` at construction; a duplicate name, or a persistent projection declaring a
 * non-positive `generation()`, where 0 is the unstamped sentinel that would silently disable the
 * staleness gate, is a fail-fast setup error.
 *
 * @see AsProjection
 */
final readonly class ProjectionRegistry
{
    /** @var array<string, Projection> */
    private array $projections;

    /**
     * @param  iterable<Projection>  $projections
     *
     * @throws DuplicateProjection when two registered projections declare the same name
     * @throws UnsupportedProjection when a persistent projection declares a non-positive generation()
     * @throws InvalidDerivedNamespace when two producers share a derived target or prefix, a prefix is
     *                                 empty, or a fixed target sits under a fan-out prefix
     */
    public function __construct(
        #[AutowireIterator('storm.projection')]
        iterable $projections = [],
    ) {
        $map = [];

        /** @var array<string, string> $fixedTargets  target stream => owning projection class */
        $fixedTargets = [];
        /** @var array<string, string> $prefixes  target prefix => owning projection class */
        $prefixes = [];

        foreach ($projections as $projection) {
            $name = $projection->name();

            if (isset($map[$name])) {
                throw DuplicateProjection::name($name, $map[$name]::class, $projection::class);
            }

            if ($projection instanceof PersistentProjection && $projection->generation() < 1) {
                throw UnsupportedProjection::invalidGeneration($name, $projection->generation());
            }

            // Derived output namespaces are OWNED: exactly one producer per target/prefix, or a reset
            // corrupts another's output. Validated here, where the instances are available, because a compile
            // pass cannot call targetStream()/targetPrefix(), so it fails loud at startup.
            if ($projection instanceof LinkProjection) {
                $target = $projection->targetStream()->toString();
                if (isset($fixedTargets[$target])) {
                    throw InvalidDerivedNamespace::duplicateFixedTarget($target, $fixedTargets[$target], $projection::class);
                }
                $fixedTargets[$target] = $projection::class;
            }

            if ($projection instanceof FanOutLinkProjection) {
                $prefix = $projection->targetPrefix();
                if (trim($prefix) === '') {
                    throw InvalidDerivedNamespace::emptyPrefix($projection::class);
                }
                foreach ($prefixes as $existing => $owner) {
                    if (str_starts_with($prefix, $existing) || str_starts_with($existing, $prefix)) {
                        throw InvalidDerivedNamespace::overlappingPrefixes($owner, $existing, $projection::class, $prefix);
                    }
                }
                $prefixes[$prefix] = $projection::class;
            }

            $map[$name] = $projection;
        }

        // A fixed target must not sit under any fan-out prefix; the fan-out's prefix-delete would take it.
        foreach ($fixedTargets as $target => $owner) {
            foreach ($prefixes as $prefix => $fanOut) {
                if (str_starts_with($target, $prefix)) {
                    throw InvalidDerivedNamespace::fixedTargetUnderPrefix($owner, $target, $fanOut, $prefix);
                }
            }
        }

        $this->projections = $map;
    }

    /**
     * @throws UnknownProjection when no projection is registered under `$name`
     */
    public function get(string $name): Projection
    {
        return $this->projections[$name] ?? throw UnknownProjection::named($name);
    }

    public function has(string $name): bool
    {
        return isset($this->projections[$name]);
    }

    /**
     * @return array<string, Projection>
     */
    public function all(): array
    {
        return $this->projections;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->projections);
    }
}
