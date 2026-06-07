<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Support;

use Kanvas\Intelligence\AgentRuntime\Enums\KanbanStatusEnum;
use Kanvas\Intelligence\AgentRuntime\Enums\KanbanTransition;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;

/**
 * Bridges the runtime kanban vocabulary and Kanvas Plan/Task statuses.
 *
 *  - Ingest (kanban → Kanvas): a root task maps onto a Plan, a child onto a Task.
 *  - Push (Kanvas → kanban): a Kanvas status change picks a lifecycle verb, but the runtime
 *    enforces a state machine — so push is gated by canTransition() against the task's CURRENT
 *    runtime status. running→done is agent-driven and observed on ingest, never pushed.
 *
 * The validity matrix is from a live Hermes run: `complete`/`block` are rejected on a `todo`
 * task ("cannot complete (unknown id or terminal state)"); only `archive` works from any state.
 */
final class KanbanStatusMapper
{
    public static function toTaskStatus(KanbanStatusEnum $status): TaskStatusEnum
    {
        return match ($status) {
            KanbanStatusEnum::TRIAGE, KanbanStatusEnum::TODO, KanbanStatusEnum::READY => TaskStatusEnum::PENDING,
            KanbanStatusEnum::RUNNING => TaskStatusEnum::IN_PROGRESS,
            KanbanStatusEnum::BLOCKED => TaskStatusEnum::BLOCKED,
            KanbanStatusEnum::DONE => TaskStatusEnum::DONE,
            KanbanStatusEnum::ARCHIVED => TaskStatusEnum::SKIPPED,
        };
    }

    public static function toPlanStatus(KanbanStatusEnum $status): PlanStatusEnum
    {
        return match ($status) {
            KanbanStatusEnum::TRIAGE => PlanStatusEnum::DRAFT,
            KanbanStatusEnum::TODO, KanbanStatusEnum::READY, KanbanStatusEnum::RUNNING => PlanStatusEnum::ACTIVE,
            KanbanStatusEnum::BLOCKED => PlanStatusEnum::BLOCKED,
            KanbanStatusEnum::DONE => PlanStatusEnum::DONE,
            KanbanStatusEnum::ARCHIVED => PlanStatusEnum::CANCELLED,
        };
    }

    // Kanvas Task status → the verb we'd push. null = no direct verb (agent-driven or no-op).
    public static function taskStatusToTransition(TaskStatusEnum $status): ?KanbanTransition
    {
        return match ($status) {
            TaskStatusEnum::DONE => KanbanTransition::COMPLETE,
            TaskStatusEnum::BLOCKED => KanbanTransition::BLOCK,
            TaskStatusEnum::SKIPPED => KanbanTransition::ARCHIVE,
            TaskStatusEnum::PENDING => KanbanTransition::UNBLOCK, // best-effort; only legal from blocked
            TaskStatusEnum::IN_PROGRESS => null,                 // running is agent-driven, observed on ingest
        };
    }

    // Kanvas Plan status → the verb we'd push. null = no direct verb.
    public static function planStatusToTransition(PlanStatusEnum $status): ?KanbanTransition
    {
        return match ($status) {
            PlanStatusEnum::DONE => KanbanTransition::COMPLETE,
            PlanStatusEnum::BLOCKED => KanbanTransition::BLOCK,
            PlanStatusEnum::CANCELLED, PlanStatusEnum::FAILED => KanbanTransition::ARCHIVE,
            default => null,
        };
    }

    // State-machine gate: is the verb legal from the task's current runtime status?
    // Issuing an illegal verb is a no-op the runtime rejects, so callers skip + log instead.
    public static function canTransition(KanbanStatusEnum $current, KanbanTransition $transition): bool
    {
        return match ($transition) {
            KanbanTransition::ARCHIVE, KanbanTransition::ASSIGN => true,
            KanbanTransition::BLOCK => in_array($current, [KanbanStatusEnum::READY, KanbanStatusEnum::RUNNING], true),
            KanbanTransition::UNBLOCK => $current === KanbanStatusEnum::BLOCKED,
            KanbanTransition::COMPLETE => $current === KanbanStatusEnum::RUNNING,
        };
    }
}
