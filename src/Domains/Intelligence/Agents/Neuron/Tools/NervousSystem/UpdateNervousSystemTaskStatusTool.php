<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem;

use Baka\Support\Str;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Contracts\RequiresSystemAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesTaskForTool;
use Kanvas\NervousSystem\Plan\Actions\UpdateTaskStatusAction;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/**
 * Lets an in-process agent (Neuron/Laravel) MOVE a task on the board — the missing half that let
 * container agents move their kanban but left in-process agents unable to change task state. Wraps
 * UpdateTaskStatusAction (which recomputes plan completion, timestamps transitions, emits ledger
 * events, broadcasts) so the agent's move is indistinguishable from a human/GraphQL move.
 */
#[AgentTool(name: 'Update Task Status', category: 'nervous_system')]
class UpdateNervousSystemTaskStatusTool extends Tool implements RequiresSystemAgent
{
    use HasKanvasContext;
    use TrackByInputs;
    use ResolvesTaskForTool;

    protected string $name = 'update_nervous_system_task_status';

    protected ?string $description = 'Move a Nervous System task to a new status (pending, in_progress, blocked, done, '
        . 'skipped). Use this to start work, mark a task done, or flag it blocked. Optionally attach a '
        . 'short result note, or a blocked_reason when blocking. To send a task back with what to fix, '
        . 'set status=pending and pass the corrected instructions as description: they replace the '
        . 'task\'s text before it runs again.';

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
            new ToolProperty(
                name: 'description',
                type: PropertyType::STRING,
                description: 'Only with status=pending: the task\'s full corrected instructions, replacing its '
                    . 'current text (keep its Repo: and Branch: lines). Omit to keep the text as it is.',
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
        ?string $description = null,
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

        if ($newStatus === TaskStatusEnum::WAITING) {
            return ['error' => 'A wait needs its wake: use snooze_task with the date, not a status change.'];
        }

        // A run's task, and the step it runs for, close when the run does.
        if ($task->hasALiveRun()) {
            return ['error' => sprintf(
                'Task #%d has a coding run that is still going; it reports its own outcome. Nothing changed. '
                    . 'If it has to stop, tell the person who asked for the work.',
                $task->getId()
            )];
        }

        // Sending a task back with what to fix has to be able to change what it says.
        $description = Str::trimToNull($description);

        if ($description !== null) {
            if ($newStatus !== TaskStatusEnum::PENDING) {
                return ['error' => 'A task\'s description is replaced only when it goes back to pending. Nothing changed.'];
            }

            if (in_array($task->status, [TaskStatusEnum::IN_PROGRESS->value, TaskStatusEnum::WAITING->value], true)) {
                return ['error' => sprintf('Task #%d is being worked right now, so its text cannot change under it. Nothing changed.', $task->getId())];
            }

            // Before the reset: going back to pending wakes the assignee, and the run reads this text.
            $task->description = $description;
            $task->saveQuietly();
        }

        // A second "done" on a done task is not a write: it would re-emit the completion and, on a
        // follow-up, re-run the close. The person thanking the agent is not asking it to act again.
        if ($task->status === $newStatus->value) {
            return [
                'task_id' => $task->getId(),
                'status' => $task->status,
                'plan_id' => $task->plan_id,
                'unchanged' => $description === null,
                'description_replaced' => $description !== null,
                'note' => $description === null
                    ? sprintf('Task #%d was already %s; nothing changed. Do not say you marked it now.', $task->getId(), $task->status)
                    : sprintf('Task #%d was already pending; its description was replaced.', $task->getId()),
            ];
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
            'description_replaced' => $description !== null,
        ];
    }
}
