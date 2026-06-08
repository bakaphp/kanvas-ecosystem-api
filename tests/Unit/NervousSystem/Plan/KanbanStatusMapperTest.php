<?php

declare(strict_types=1);

namespace Tests\Unit\NervousSystem\Plan;

use Kanvas\Intelligence\AgentRuntime\Enums\KanbanStatusEnum;
use Kanvas\Intelligence\AgentRuntime\Enums\KanbanTransition;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Support\KanbanStatusMapper;
use PHPUnit\Framework\TestCase;

final class KanbanStatusMapperTest extends TestCase
{
    public function testEveryKanbanStatusMapsToATaskStatus(): void
    {
        $expected = [
            'triage' => TaskStatusEnum::PENDING,
            'todo' => TaskStatusEnum::PENDING,
            'ready' => TaskStatusEnum::PENDING,
            'running' => TaskStatusEnum::IN_PROGRESS,
            'blocked' => TaskStatusEnum::BLOCKED,
            'done' => TaskStatusEnum::DONE,
            'archived' => TaskStatusEnum::SKIPPED,
        ];

        foreach (KanbanStatusEnum::cases() as $status) {
            $this->assertSame(
                $expected[$status->value],
                KanbanStatusMapper::toTaskStatus($status),
                "task status mapping for {$status->value}",
            );
        }
    }

    public function testEveryKanbanStatusMapsToAPlanStatus(): void
    {
        $expected = [
            'triage' => PlanStatusEnum::DRAFT,
            'todo' => PlanStatusEnum::ACTIVE,
            'ready' => PlanStatusEnum::ACTIVE,
            'running' => PlanStatusEnum::ACTIVE,
            'blocked' => PlanStatusEnum::BLOCKED,
            'done' => PlanStatusEnum::DONE,
            'archived' => PlanStatusEnum::CANCELLED,
        ];

        foreach (KanbanStatusEnum::cases() as $status) {
            $this->assertSame(
                $expected[$status->value],
                KanbanStatusMapper::toPlanStatus($status),
                "plan status mapping for {$status->value}",
            );
        }
    }

    public function testTaskStatusToTransition(): void
    {
        $this->assertSame(KanbanTransition::COMPLETE, KanbanStatusMapper::taskStatusToTransition(TaskStatusEnum::DONE));
        $this->assertSame(KanbanTransition::BLOCK, KanbanStatusMapper::taskStatusToTransition(TaskStatusEnum::BLOCKED));
        $this->assertSame(KanbanTransition::ARCHIVE, KanbanStatusMapper::taskStatusToTransition(TaskStatusEnum::SKIPPED));
        $this->assertSame(KanbanTransition::UNBLOCK, KanbanStatusMapper::taskStatusToTransition(TaskStatusEnum::PENDING));
        // running is agent-driven — observed on ingest, never pushed.
        $this->assertNull(KanbanStatusMapper::taskStatusToTransition(TaskStatusEnum::IN_PROGRESS));
    }

    public function testPlanStatusToTransition(): void
    {
        $this->assertSame(KanbanTransition::COMPLETE, KanbanStatusMapper::planStatusToTransition(PlanStatusEnum::DONE));
        $this->assertSame(KanbanTransition::BLOCK, KanbanStatusMapper::planStatusToTransition(PlanStatusEnum::BLOCKED));
        $this->assertSame(KanbanTransition::ARCHIVE, KanbanStatusMapper::planStatusToTransition(PlanStatusEnum::CANCELLED));
        $this->assertSame(KanbanTransition::ARCHIVE, KanbanStatusMapper::planStatusToTransition(PlanStatusEnum::FAILED));
        $this->assertNull(KanbanStatusMapper::planStatusToTransition(PlanStatusEnum::DRAFT));
        $this->assertNull(KanbanStatusMapper::planStatusToTransition(PlanStatusEnum::ACTIVE));
        $this->assertNull(KanbanStatusMapper::planStatusToTransition(PlanStatusEnum::AWAITING_APPROVAL));
    }

    public function testCanTransitionEnforcesTheLiveVerifiedStateMachine(): void
    {
        // archive + assign are always legal.
        foreach (KanbanStatusEnum::cases() as $status) {
            $this->assertTrue(KanbanStatusMapper::canTransition($status, KanbanTransition::ARCHIVE));
            $this->assertTrue(KanbanStatusMapper::canTransition($status, KanbanTransition::ASSIGN));
        }

        // complete only from running (live: "cannot complete" on a todo task).
        $this->assertTrue(KanbanStatusMapper::canTransition(KanbanStatusEnum::RUNNING, KanbanTransition::COMPLETE));
        $this->assertFalse(KanbanStatusMapper::canTransition(KanbanStatusEnum::TODO, KanbanTransition::COMPLETE));
        $this->assertFalse(KanbanStatusMapper::canTransition(KanbanStatusEnum::READY, KanbanTransition::COMPLETE));

        // block only from ready/running.
        $this->assertTrue(KanbanStatusMapper::canTransition(KanbanStatusEnum::READY, KanbanTransition::BLOCK));
        $this->assertTrue(KanbanStatusMapper::canTransition(KanbanStatusEnum::RUNNING, KanbanTransition::BLOCK));
        $this->assertFalse(KanbanStatusMapper::canTransition(KanbanStatusEnum::TODO, KanbanTransition::BLOCK));

        // unblock only from blocked.
        $this->assertTrue(KanbanStatusMapper::canTransition(KanbanStatusEnum::BLOCKED, KanbanTransition::UNBLOCK));
        $this->assertFalse(KanbanStatusMapper::canTransition(KanbanStatusEnum::READY, KanbanTransition::UNBLOCK));
    }
}
