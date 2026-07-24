<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesPlanForTool;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Project\Jobs\WakeWorkerForPlanJob;
use Kanvas\Users\Models\Users;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * The PM's primary delegation verb: hand a whole PLAN to a member — a HUMAN or an AGENT. A project is
 * a mixed team, so either can own a plan. Assign to a member (pass exactly one of agent_id / users_id):
 *  - executor agent  → it wakes, decomposes into subtasks, executes, and reports (auto-run);
 *  - human, or a non-executor/remote agent → recorded as owner, NOT auto-run — @mention them so they
 *    (or their own runtime) do the work and report progress.
 */
#[AgentTool(name: 'Assign Plan')]
class AssignNervousSystemPlanTool extends Tool
{
    use HasKanvasContext;
    use ResolvesPlanForTool;

    public function __construct()
    {
        parent::__construct(
            name: 'assign_nervous_system_plan',
            description: 'Assign a whole plan to a project member — a human OR an agent (pass exactly one of '
                . 'agent_id or users_id, from the members list). An executor agent auto-runs the plan; a human '
                . '(or a non-executor agent) is recorded as owner but does NOT auto-run — @mention them to '
                . 'notify. Returns whether the assignee will auto-run.',
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
                description: 'Assign to this member AGENT (from members). Omit if assigning to a human.',
                required: false,
            ),
            new ToolProperty(
                name: 'users_id',
                type: PropertyType::INTEGER,
                description: 'Assign to this member HUMAN (from members). Omit if assigning to an agent.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $plan_id, ?int $agent_id = null, ?int $users_id = null): array
    {
        if (($agent_id === null) === ($users_id === null)) {
            return ['error' => 'Provide exactly one of agent_id or users_id (from the project members).'];
        }

        $plan = $this->resolvePlanOrError($plan_id, "Plan {$plan_id} was not found in this project.");

        if (is_array($plan)) {
            return $plan;
        }

        return $agent_id !== null
            ? $this->assignToAgent($plan, $agent_id)
            : $this->assignToHuman($plan, (int) $users_id);
    }

    /**
     * @return array<string, mixed>
     */
    private function assignToAgent(Plan $plan, int $agentId): array
    {
        $agent = Agent::query()
            ->where('id', $agentId)
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->first();

        if ($agent === null) {
            return ['error' => "Agent {$agentId} was not found — pick an agent_id from the project members."];
        }

        // Loop-breaker: an agent that already blocked this plan for a capability reason can't do the
        // work — re-handing it there just re-blocks (NS-6909). Force the PM to pick someone else.
        if (in_array($agent->getId(), $plan->capability_declined_agent_ids ?? [], true)) {
            return [
                'error' => sprintf(
                    'Agent %d (%s) already blocked this plan — it lacks the capability. Assign it to a '
                    . 'DIFFERENT agent or a human; do not re-assign the same one.',
                    $agent->getId(),
                    $agent->name,
                ),
            ];
        }

        $plan->agent_id = $agent->getId();
        $plan->assigned_users_id = null;
        $plan->saveQuietly();

        // Only an executor agent auto-runs. A non-executor (remote/container/Lead-context) is recorded
        // as owner but not woken here — its own runtime drives it, or the PM @mentions it.
        $autoRun = $agent->canExecuteBoardWork();
        if ($autoRun) {
            WakeWorkerForPlanJob::dispatch($plan);
        }

        return [
            'plan_id' => $plan->getId(),
            'assignee_type' => 'agent',
            'agent_id' => $agent->getId(),
            'name' => $agent->name,
            'auto_run' => $autoRun,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function assignToHuman(Plan $plan, int $usersId): array
    {
        $user = Users::query()
            ->where('id', $usersId)
            ->first();

        if ($user === null) {
            return ['error' => "User {$usersId} was not found — pick a users_id from the project members."];
        }

        $plan->agent_id = null;
        $plan->assigned_users_id = $user->getId();
        $plan->saveQuietly();

        return [
            'plan_id' => $plan->getId(),
            'assignee_type' => 'user',
            'users_id' => $user->getId(),
            'name' => trim(($user->firstname ?? '') . ' ' . ($user->lastname ?? '')) ?: $user->displayname,
            'auto_run' => false,
            'note' => 'Human owner — not auto-run. @mention them so they know the plan is theirs.',
        ];
    }
}
