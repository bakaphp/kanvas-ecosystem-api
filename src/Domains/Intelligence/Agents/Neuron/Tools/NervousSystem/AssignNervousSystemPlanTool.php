<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Project\Jobs\WakeWorkerForPlanJob;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * The PM's primary delegation verb: hand a whole PLAN to a member agent, which then OWNS it — breaks
 * it into subtasks, executes, comments, and completes. Assigning a plan (not a single task) is what
 * gives the worker room to decompose and drive the work. Sets Plan.agent_id and wakes the worker with
 * a scoped board toolset.
 */
#[AgentTool(name: 'Assign Plan')]
class AssignNervousSystemPlanTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'assign_nervous_system_plan',
            description: 'Assign a whole plan to a member agent so that agent owns and executes it — it will '
                . 'break the plan into subtasks, do the work, and report. Use this to delegate a stream of '
                . 'work to the best-fit member agent (use the agent_id from members).',
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
                name: 'plan_id',
                type: PropertyType::INTEGER,
                description: 'The plan to assign.',
                required: true,
            ),
            new ToolProperty(
                name: 'agent_id',
                type: PropertyType::INTEGER,
                description: 'The member agent to assign the plan to.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $plan_id, int $agent_id): array
    {
        $plan = Plan::query()
            ->where('id', $plan_id)
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->first();

        if ($plan === null) {
            return ['error' => "Plan {$plan_id} was not found in this project."];
        }
        /** @var Plan $plan */

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
            return ['error' => "Agent '{$agent->name}' can't execute plan work (it's a remote/container or "
                . 'non-executor agent). Assign to a member whose can_execute is true.'];
        }

        $plan->agent_id = $agent->getId();
        $plan->saveQuietly();

        // The assignee owns the plan now — wake it to decompose and execute.
        WakeWorkerForPlanJob::dispatch($plan);

        return [
            'plan_id' => $plan->getId(),
            'agent_id' => $agent->getId(),
            'agent_name' => $agent->name,
        ];
    }
}
