<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Tests\TestCase;
use ValueError;

class PlanStatusAliasTest extends TestCase
{
    public function testTaskStatusAcceptsCompletedAsDone(): void
    {
        $this->assertSame(TaskStatusEnum::DONE, TaskStatusEnum::fromAlias('completed'));
        $this->assertSame(TaskStatusEnum::DONE, TaskStatusEnum::fromAlias('Complete'));
        $this->assertSame(TaskStatusEnum::DONE, TaskStatusEnum::fromAlias('FINISHED'));
        $this->assertSame(TaskStatusEnum::DONE, TaskStatusEnum::fromAlias('done'));
    }

    public function testTaskStatusAcceptsStartedAsInProgress(): void
    {
        $this->assertSame(TaskStatusEnum::IN_PROGRESS, TaskStatusEnum::fromAlias('started'));
        $this->assertSame(TaskStatusEnum::IN_PROGRESS, TaskStatusEnum::fromAlias('start'));
        $this->assertSame(TaskStatusEnum::IN_PROGRESS, TaskStatusEnum::fromAlias('in-progress'));
        $this->assertSame(TaskStatusEnum::IN_PROGRESS, TaskStatusEnum::fromAlias('inprogress'));
        $this->assertSame(TaskStatusEnum::IN_PROGRESS, TaskStatusEnum::fromAlias('in_progress'));
    }

    public function testPlanStatusAcceptsCanceledAndCompleted(): void
    {
        $this->assertSame(PlanStatusEnum::CANCELLED, PlanStatusEnum::fromAlias('canceled'));
        $this->assertSame(PlanStatusEnum::CANCELLED, PlanStatusEnum::fromAlias('cancel'));
        $this->assertSame(PlanStatusEnum::DONE, PlanStatusEnum::fromAlias('completed'));
        $this->assertSame(PlanStatusEnum::ACTIVE, PlanStatusEnum::fromAlias('started'));
        $this->assertSame(PlanStatusEnum::AWAITING_APPROVAL, PlanStatusEnum::fromAlias('needs_approval'));
    }

    public function testFromAliasStillRejectsGarbage(): void
    {
        $this->expectException(ValueError::class);
        TaskStatusEnum::fromAlias('not_a_real_status');
    }

    public function testFromAliasIsCaseInsensitiveAndTrims(): void
    {
        $this->assertSame(TaskStatusEnum::DONE, TaskStatusEnum::fromAlias('  DONE  '));
        $this->assertSame(PlanStatusEnum::DRAFT, PlanStatusEnum::fromAlias('Draft'));
    }
}
