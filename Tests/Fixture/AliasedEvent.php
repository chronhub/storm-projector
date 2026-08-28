<?php

declare(strict_types=1);

namespace Storm\Projector\Tests\Fixture;

use Storm\Message\EventType;

#[EventType('test.aliased')]
final class AliasedEvent {}
