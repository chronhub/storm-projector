<?php

declare(strict_types=1);

namespace Storm\Projector\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Chronicler\Evolution\IdentityEventTypeMapper;
use Storm\Chronicler\Evolution\MappedEventTypeMapper;
use Storm\Projector\Run\FilterTypes;
use Storm\Projector\Tests\Fixture\AliasedEvent;
use Storm\Projector\Tests\Fixture\RenamedEvent;
use Storm\Projector\Tests\Fixture\SomethingHappened;

final class FilterTypesTest extends TestCase
{
    #[Test]
    public function empty_classes_resolve_to_empty_types_meaning_all(): void
    {
        $this->assertSame([], FilterTypes::resolve(new IdentityEventTypeMapper, []));
        $this->assertSame([], FilterTypes::resolve(new MappedEventTypeMapper([AliasedEvent::class]), []));
    }

    #[Test]
    public function the_identity_mapper_keeps_the_fqcn(): void
    {
        $this->assertSame(
            [SomethingHappened::class],
            FilterTypes::resolve(new IdentityEventTypeMapper, [SomethingHappened::class]),
        );
    }

    #[Test]
    public function an_aliased_class_expands_to_its_alias_and_fqcn(): void
    {
        $types = FilterTypes::resolve(new MappedEventTypeMapper([AliasedEvent::class]), [AliasedEvent::class]);

        $this->assertSame(['test.aliased', AliasedEvent::class], $types);
    }

    #[Test]
    public function several_classes_expand_and_deduplicate(): void
    {
        $mapper = new MappedEventTypeMapper([AliasedEvent::class, RenamedEvent::class]);

        $types = FilterTypes::resolve($mapper, [AliasedEvent::class, RenamedEvent::class, AliasedEvent::class]);

        // alias and fqcn for each, replaced alias for RenamedEvent, no duplicates
        $this->assertSame(
            ['test.aliased', AliasedEvent::class, 'test.renamed', 'test.old', RenamedEvent::class],
            $types,
        );
    }
}
