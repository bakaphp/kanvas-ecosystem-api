<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesTaskForTool;
use Kanvas\NervousSystem\Plan\Actions\DeleteTaskAction;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Project\Models\Project;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * Lets the PM remove a task that's no longer needed (superseded, duplicated, or wrong). Soft-deletes
 * via DeleteTaskAction and rolls the project's completion back up.
 */
#[AgentTool(name: 'Delete Task', category: 'nervous_system')]
class DeleteNervousSystemTaskTool extends Tool implements HasRunKey
{
    use HasKanvasContext;
    use TrackByInputs;
    use ResolvesTaskForTool;

    public function __construct()
    {
        parent::__construct(
            name: 'delete_nervous_system_task',
            description: 'Remove a task that is no longer needed (superseded, duplicate, or created in error). '
                . 'Prefer marking a task skipped/done over deleting when it actually happened; delete only when '
                . 'the task should not exist.',
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
                description: 'The id of the task to delete.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $task_id): array
    {
        $task = $this->resolveTaskOrError($task_id, "Task {$task_id} was not found in this project.");

        if (is_array($task)) {
            return $task;
        }

        $planId = $task->plan_id;

        $deleted = new DeleteTaskAction($task)->execute();

        $projectId = Plan::query()->where('id', $planId)->value('project_id');
        if ($projectId !== null) {
            Project::query()->where('id', (int) $projectId)->first()?->recomputeCompletionPct();
        }

        return [
            'task_id' => $task_id,
            'deleted' => $deleted,
        ];
    }
}
