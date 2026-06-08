<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Actions\Kanban;

use Illuminate\Support\Carbon;
use Kanvas\Intelligence\AgentRuntime\DataTransferObject\KanbanTask;
use Kanvas\Intelligence\AgentRuntime\Enums\KanbanCustomFieldEnum;
use Kanvas\Intelligence\AgentRuntime\Enums\KanbanStatusEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\NervousSystem\Plan\Actions\AddTaskAction;
use Kanvas\NervousSystem\Plan\Actions\UpdateTaskStatusAction;
use Kanvas\NervousSystem\Plan\DataTransferObject\Task as TaskData;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\NervousSystem\Plan\Support\KanbanStatusMapper;

/**
 * Upsert a Kanvas Task from a runtime child task, under the given Plan. Status-diffed against the
 * stored AGENT_KANBAN_STATUS; every write is `fromSync: true`. The worker handoff (latest_summary
 * + run metadata) is mirrored into the Task `result` on completion — that's where the runtime puts
 * output, not its own `result` field.
 */
final class UpsertKanbanTaskAction
{
    public function __construct(
        private readonly KanbanTask $child,
        private readonly Plan $plan,
        private readonly ?Task $existing,
        private readonly AgentDeployment $deployment,
        private readonly Agent $agent,
    ) {
    }

    public function execute(): Task
    {
        $mapped = KanbanStatusMapper::toTaskStatus($this->child->status);
        $rawStatus = $this->child->status->value;
        $title = $this->child->title !== '' ? $this->child->title : '(untitled)';

        if ($this->existing === null) {
            $task = new AddTaskAction(
                $this->plan,
                new TaskData(
                    plan: $this->plan,
                    title: $title,
                    description: $this->child->body,
                    status: $mapped,
                    result: $this->buildResult(),
                ),
                fromSync: true,
            )->execute();
        } else {
            $task = $this->existing;

            if ($this->existing->get(KanbanCustomFieldEnum::STATUS->value) !== $rawStatus) {
                $task = new UpdateTaskStatusAction(
                    $this->existing,
                    $mapped,
                    result: $this->buildResult(),
                    fromSync: true,
                )->execute();
            }
        }

        $dirty = false;

        if ($task->agent_id !== $this->agent->getId()) {
            $task->agent_id = $this->agent->getId();
            $dirty = true;
        }

        // Stamp the runtime's own started/completed times (epoch); the status action only sets now().
        if ($this->child->startedAt !== null) {
            $task->started_at = Carbon::createFromTimestamp($this->child->startedAt);
            $dirty = true;
        }

        if ($this->child->completedAt !== null) {
            $task->completed_at = Carbon::createFromTimestamp($this->child->completedAt);

            if ($task->started_at === null) {
                $task->started_at = $task->completed_at;
            }

            $dirty = true;
        }

        if ($dirty) {
            $task->saveQuietly();
        }

        $this->writeLink($task, $rawStatus);

        return $task;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildResult(): ?array
    {
        if ($this->child->status !== KanbanStatusEnum::DONE || $this->child->metadata === null) {
            return null;
        }

        return [
            'handoff' => $this->child->latestSummary,
            'metadata' => $this->child->metadata,
        ];
    }

    private function writeLink(Task $task, string $rawStatus): void
    {
        $parentIds = json_encode($this->child->parentIds);

        $task->set(KanbanCustomFieldEnum::TASK_ID->value, $this->child->id);
        $task->set(KanbanCustomFieldEnum::STATUS->value, $rawStatus);
        $task->set(KanbanCustomFieldEnum::DEPLOYMENT_ID->value, (string) $this->deployment->getId());
        $task->set(KanbanCustomFieldEnum::PARENT_IDS->value, $parentIds === false ? '[]' : $parentIds);
        $task->set(KanbanCustomFieldEnum::SYNCED_AT->value, Carbon::now()->toIso8601String());
    }
}
