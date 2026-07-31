<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesTaskForTool;
use Kanvas\NervousSystem\Project\Jobs\WakeAgentForTaskJob;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * Lets the PM assign a task to a member agent — the "delegate the work" verb of orchestration. The
 * assigned agent becomes the task's executor (Task.agent_id).
 */
#[AgentTool(name: 'Assign Task', category: 'nervous_system')]
class AssignNervousSystemTaskTool extends Tool
{
    use HasKanvasContext;
    use ResolvesTaskForTool;

    public function __construct()
    {
        parent::__construct(
            name: 'assign_nervous_system_task',
            description: 'Assign a task to an agent so that agent owns and executes it. Use this to delegate '
                . 'a task to the best-fit member agent.',
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
                description: 'The task to assign.',
                required: true,
            ),
            new ToolProperty(
                name: 'agent_id',
                type: PropertyType::INTEGER,
                description: 'The agent to assign the task to.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $task_id, int $agent_id): array
    {
        $task = $this->resolveTaskOrError($task_id, "Task {$task_id} was not found in this project.");

        if (is_array($task)) {
            return $task;
        }

        $agent = Agent::query()
            ->where('id', $agent_id)
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->first();

        if ($agent === null) {
            return ['error' => "Agent {$agent_id} was not found — pick an agent_id from the project members."];
        }

        if (! $agent->canExecuteBoardWork()) {
            return ['error' => "Agent '{$agent->name}' can't execute task work (it's a remote/container or "
                . 'non-executor agent). Assign to a member whose can_execute is true.'];
        }

        $task->agent_id = $agent->getId();
        $task->saveQuietly();

        // Delegation means the assignee actually runs — wake it to execute the task.
        WakeAgentForTaskJob::dispatch($task);

        return [
            'task_id' => $task->getId(),
            'agent_id' => $agent->getId(),
            'agent_name' => $agent->name,
        ];
    }
}
