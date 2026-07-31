<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesTaskForTool;
use Kanvas\NervousSystem\Plan\Actions\UpdateTaskStatusAction;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/**
 * Lets an in-process agent (Neuron/Laravel) MOVE a task on the board — the missing half that let
 * container agents move their kanban but left in-process agents unable to change task state. Wraps
 * UpdateTaskStatusAction (which recomputes plan completion, timestamps transitions, emits ledger
 * events, broadcasts) so the agent's move is indistinguishable from a human/GraphQL move.
 */
#[AgentTool(name: 'Update Task Status', category: 'nervous_system')]
class UpdateNervousSystemTaskStatusTool extends Tool
{
    use HasKanvasContext;
    use ResolvesTaskForTool;

    public function __construct()
    {
        parent::__construct(
            name: 'update_nervous_system_task_status',
            description: 'Move a Nervous System task to a new status (pending, in_progress, blocked, done, '
                . 'skipped). Use this to start work, mark a task done, or flag it blocked. Optionally attach a '
                . 'short result note, or a blocked_reason when blocking.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'task_id',
                type: PropertyType::INTEGER,
                description: 'The id of the task to move.',
                required: true,
            ),
            new ToolProperty(
                name: 'status',
                type: PropertyType::STRING,
                description: 'New status: pending | in_progress | blocked | done | skipped.',
                required: true,
            ),
            new ToolProperty(
                name: 'result',
                type: PropertyType::STRING,
                description: 'Optional short note on the outcome (stored on the task result).',
                required: false,
            ),
            new ToolProperty(
                name: 'blocked_reason',
                type: PropertyType::STRING,
                description: 'Why the task is blocked — required in spirit when status is blocked.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        int $task_id,
        string $status,
        ?string $result = null,
        ?string $blocked_reason = null,
    ): array {
        $task = $this->resolveTaskOrError($task_id, "Task {$task_id} was not found in this project.");

        if (is_array($task)) {
            return $task;
        }

        try {
            $newStatus = TaskStatusEnum::fromAlias($status);
        } catch (Throwable) {
            return ['error' => "Invalid status '{$status}'. Use one of: pending, in_progress, blocked, done, skipped."];
        }

        $updated = new UpdateTaskStatusAction(
            task: $task,
            newStatus: $newStatus,
            result: $result !== null && $result !== '' ? ['note' => $result] : null,
            blockedReason: $blocked_reason,
        )->execute();

        return [
            'task_id' => $updated->getId(),
            'status' => $updated->status,
            'plan_id' => $updated->plan_id,
        ];
    }
}
