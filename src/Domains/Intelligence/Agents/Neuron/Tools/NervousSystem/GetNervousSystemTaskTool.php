<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesTaskForTool;
use Kanvas\NervousSystem\Plan\Support\MentionHandle;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * Read one task — including which plan and project it belongs to.
 *
 * There were five task tools and not one of them could READ a task: an agent could create, assign,
 * move and delete work it could not look at. On plan 26531 that cost two round trips and part of the
 * conversation budget — the PM asked "which project does Task #11890 belong to? I manage two", and
 * the worker answered "I do not currently have access to a tool to retrieve task details (such as a
 * `get_nervous_system_task`)", naming the tool that did not exist.
 */
#[AgentTool(name: 'Get Task', category: 'nervous_system')]
class GetNervousSystemTaskTool extends Tool implements HasRunKey
{
    use HasKanvasContext;
    use ResolvesTaskForTool;

    // Per-item by nature: resolving the six tasks named in a status report is six calls, and the
    // default per-name budget of 10 would abort the turn partway through a longer board.
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'get_nervous_system_task',
            description: 'Read a task by id: its title, status, blocker, result, who it is assigned to, '
                . 'and the plan and project it belongs to. Use it whenever someone names a task id you '
                . 'do not already have in front of you — it answers "which plan/project is this on?" '
                . 'without asking anyone.',
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
                description: 'The task to read.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $task_id): array
    {
        $task = $this->resolveTaskOrError($task_id);

        if (is_array($task)) {
            return $task;
        }

        $plan = $task->plan;
        $project = $plan?->project;

        return [
            'task_id' => $task->getId(),
            'title' => $task->title,
            'description' => $task->description,
            'status' => $task->status,
            'sequence' => $task->sequence,
            'blocked_reason' => $task->blocked_reason,
            'result' => $task->result,
            'assigned_agent_id' => $task->agent_id,
            'assigned_agent_name' => $task->agent?->name,
            // How to actually reach them: an @mention must be the handle, never the display name.
            'assigned_agent_handle' => MentionHandle::forUser($task->agent?->user, $this->app),
            'plan_id' => $plan?->getId(),
            'plan_title' => $plan?->title,
            'plan_status' => $plan?->status,
            'project_id' => $project?->getId(),
            'project_title' => $project?->title,
        ];
    }
}
