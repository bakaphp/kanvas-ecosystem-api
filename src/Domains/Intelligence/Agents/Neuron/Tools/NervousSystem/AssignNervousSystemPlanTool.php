<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ReportsToolOutcome;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesPlanForTool;
use Kanvas\NervousSystem\Plan\Enums\PlanChangeTypeEnum;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Notifications\PlanProgressNotification;
use Kanvas\NervousSystem\Project\Enums\ProjectMemberTypeEnum;
use Kanvas\NervousSystem\Project\Models\ProjectMember;
use Kanvas\Users\Models\Users;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * The PM's primary delegation verb: hand a whole PLAN to a member — a HUMAN or an AGENT. A project is
 * a mixed team, so either can own a plan. Assign to a member (pass exactly one of agent_id / users_id):
 *  - executor agent  → it wakes, decomposes into subtasks, executes, and reports (auto-run);
 *  - human, or a non-executor/remote agent → recorded as owner, NOT auto-run — @mention them so they
 *    (or their own runtime) do the work and report progress.
 */
#[AgentTool(name: 'Assign Plan', category: 'nervous_system')]
class AssignNervousSystemPlanTool extends Tool implements HasRunKey
{
    use HasKanvasContext;
    use ReportsToolOutcome;
    use TrackByInputs;
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

        // An intake plan has no agreed brief yet, so there is nothing to assign against. Enforced at
        // the entry point rather than trusted to the prompt, and above the agent/human fork so both
        // assignee kinds are covered — a guard that only holds on one path is not a guard.
        $blocked = $this->refuseIfNotExecutable($plan);

        if ($blocked !== null) {
            return $blocked;
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

        // Reported, never enforced: these are the REGISTRY grants only — an agent also carries its
        // handler class's baseline, so an empty list does not mean it can do nothing.
        $grantedTools = $agent->selectedTools()->pluck('name')->all();

        // Only an executor agent auto-runs. A non-executor (remote/container/Lead-context) is recorded
        // as owner but not woken here — its own runtime drives it, or the PM @mentions it.
        $autoRun = $agent->canExecuteBoardWork();

        // Idempotent: the plan is already this agent's. Re-assigning would re-dispatch the worker and,
        // for a non-executor (auto_run=false), the model can read the unchanged result as "didn't work"
        // and call the identical args again until it trips ToolRunsExceededException. NOOP is exactly
        // this case — a correct call that changed nothing — so the "stop, move on" wording comes from
        // the enum rather than being restated here.
        if ((int) $plan->agent_id === $agent->getId()) {
            return $this->noop(
                [
                    'plan_id' => $plan->getId(),
                    'assignee_type' => 'agent',
                    'agent_id' => $agent->getId(),
                    'name' => $agent->name,
                    'auto_run' => $autoRun,
                    'agent_tools' => $grantedTools,
                    'already_assigned' => true,
                ],
                'This plan is ALREADY assigned to this agent — assignment is complete.',
            );
        }

        $plan->agent_id = $agent->getId();
        $plan->assigned_users_id = null;
        $plan->save();

        // Broadcast rather than dispatching a wake by hand. `saveQuietly()` + a direct
        // WakeWorkerForPlanJob meant assignment never reached WakeAgentOnPlanChangeListener, so the
        // continuation loop and the task band — everything that RUNS the work and records what it
        // produced — were only ever entered by accident, when some later task transition happened to
        // trip the listener. On the hand-dispatched path the agent had to mark its own tasks done, and
        // an agent that answers the question but forgets the status write leaves no error anywhere:
        // plan 13765 sat with a researched, posted answer and its task still `pending`.
        if ($autoRun) {
            $plan->broadcastChange(PlanChangeTypeEnum::ASSIGNED);
        }

        return [
            'plan_id' => $plan->getId(),
            'assignee_type' => 'agent',
            'agent_id' => $agent->getId(),
            'name' => $agent->name,
            'auto_run' => $autoRun,
            'agent_tools' => $grantedTools,
            ...($grantedTools === [] ? ['warning' => sprintf(
                '%s has no tools granted to it beyond its built-in ones. If this plan needs Kanvas '
                . 'work it cannot do, grant what it is missing rather than waiting for it to block.',
                $agent->name,
            )] : []),
            'message' => $autoRun
                ? 'Assigned. The agent will auto-run this plan. Assignment is complete — do not assign again.'
                : 'Assigned as owner (this agent does not auto-run). Assignment is complete — do not assign '
                    . 'again; @mention them if you need them to act.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function assignToHuman(Plan $plan, int $usersId): array
    {
        // Only a HUMAN member of THIS plan's project can own it. Scoping through project membership
        // (not a bare global Users lookup) both keeps the assignment tenant-safe and forces the PM to
        // pick a real "type: user" member — never a same-named agent's user or a cross-tenant id.
        $member = ProjectMember::query()
            ->where('project_id', $plan->project_id)
            ->where('member_type', ProjectMemberTypeEnum::USER->value)
            ->where('users_id', $usersId)
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->with('user')
            ->first();

        /** @var Users|null $user */
        $user = $member?->user;
        if ($user === null) {
            return [
                'error' => "User {$usersId} is not a human (type: user) member of this project — pick a "
                    . 'users_id from a member whose type is "user".',
            ];
        }

        // Idempotent: already this human's. Short-circuit BEFORE re-notifying — otherwise a model that
        // repeats the identical call fires a duplicate push/email on every retry (and can loop to the
        // tool-run cap). Tell it the assignment is done so it stops.
        if ((int) $plan->assigned_users_id === $user->getId() && $plan->agent_id === null) {
            return [
                'plan_id' => $plan->getId(),
                'assignee_type' => 'user',
                'users_id' => $user->getId(),
                'name' => trim($user->firstname . ' ' . $user->lastname) ?: $user->displayname,
                'auto_run' => false,
                'notified' => false,
                'already_assigned' => true,
                'message' => 'This plan is ALREADY assigned to this person — assignment is complete. Do '
                    . 'NOT call assign again for this plan; move on.',
            ];
        }

        $plan->agent_id = null;
        $plan->assigned_users_id = $user->getId();
        $plan->saveQuietly();

        // Notify the new owner directly — don't rely on the PM remembering to @mention them. A human
        // assignee doesn't auto-run, so this is the only signal that the plan is now theirs.
        $user->notify(new PlanProgressNotification(
            $plan,
            'You were assigned a plan',
            sprintf('You were assigned the plan "%s".', $plan->title),
            [
                'plan_id' => $plan->getId(),
                'plan_uuid' => $plan->uuid,
                'change_type' => 'assigned',
                'status' => $plan->status,
            ],
        ));

        return [
            'plan_id' => $plan->getId(),
            'assignee_type' => 'user',
            'users_id' => $user->getId(),
            'name' => trim($user->firstname . ' ' . $user->lastname) ?: $user->displayname,
            'auto_run' => false,
            'notified' => true,
            'note' => 'Human owner — not auto-run. They were notified of the assignment; @mention them '
                . 'only if you also need something from them now.',
        ];
    }

    /**
     * A refusal when the plan's status forbids dispatch, or null when it is fine to proceed.
     *
     * @return array<string, mixed>|null
     */
    private function refuseIfNotExecutable(Plan $plan): ?array
    {
        $status = PlanStatusEnum::tryFrom($plan->status);

        if ($status === null || $status->isExecutable()) {
            return null;
        }

        return [
            'error' => $status === PlanStatusEnum::INTAKE
                ? sprintf(
                    'Plan %d is still in intake — its brief is not agreed yet, so it cannot be assigned or '
                    . 'started. Finish the intake questions first.',
                    $plan->getId(),
                )
                : sprintf(
                    'Plan %d is awaiting human approval and cannot be assigned or started until someone '
                    . 'approves it.',
                    $plan->getId(),
                ),
        ];
    }
}
