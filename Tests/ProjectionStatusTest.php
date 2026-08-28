<?php

declare(strict_types=1);

namespace Storm\Projector\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Projector\Store\ProjectionStatus;

/**
 * The state-machine transition predicates, the single source the runner's start guard and the
 * `mark` / `retry` console commands delegate to, so the rules can't drift across call sites.
 */
final class ProjectionStatusTest extends TestCase
{
    #[Test]
    public function only_idle_and_running_are_runnable(): void
    {
        self::assertTrue(ProjectionStatus::Idle->isRunnable());
        self::assertTrue(ProjectionStatus::Running->isRunnable());
        self::assertFalse(ProjectionStatus::Paused->isRunnable());
        self::assertFalse(ProjectionStatus::Stopping->isRunnable());
        self::assertFalse(ProjectionStatus::Failed->isRunnable());
        // the SQL gate reads these very strings: exactly the two runnable wire values, as strings
        self::assertSame(
            [ProjectionStatus::Idle->value, ProjectionStatus::Running->value],
            ProjectionStatus::runnableValues(),
        );
    }

    #[Test]
    public function only_idle_or_running_can_pause(): void
    {
        self::assertTrue(ProjectionStatus::Idle->canPause());
        self::assertTrue(ProjectionStatus::Running->canPause());
        self::assertFalse(ProjectionStatus::Paused->canPause());
        self::assertFalse(ProjectionStatus::Stopping->canPause());
        self::assertFalse(ProjectionStatus::Failed->canPause());
    }

    #[Test]
    public function only_paused_can_resume(): void
    {
        self::assertTrue(ProjectionStatus::Paused->canResume());
        self::assertFalse(ProjectionStatus::Idle->canResume());
        self::assertFalse(ProjectionStatus::Running->canResume());
        self::assertFalse(ProjectionStatus::Stopping->canResume());
        self::assertFalse(ProjectionStatus::Failed->canResume()); // a failed one recovers via retry / reset
    }

    #[Test]
    public function only_running_or_stopping_can_stop(): void
    {
        self::assertTrue(ProjectionStatus::Running->canStop());
        self::assertTrue(ProjectionStatus::Stopping->canStop()); // idempotent re-stop, the door out of a crashed worker's leftover
        self::assertFalse(ProjectionStatus::Idle->canStop());
        self::assertFalse(ProjectionStatus::Paused->canStop());
        self::assertFalse(ProjectionStatus::Failed->canStop());
    }

    #[Test]
    public function only_failed_can_retry(): void
    {
        self::assertTrue(ProjectionStatus::Failed->canRetry());
        self::assertFalse(ProjectionStatus::Idle->canRetry());
        self::assertFalse(ProjectionStatus::Running->canRetry());
        self::assertFalse(ProjectionStatus::Paused->canRetry());
        self::assertFalse(ProjectionStatus::Stopping->canRetry());
    }
}
