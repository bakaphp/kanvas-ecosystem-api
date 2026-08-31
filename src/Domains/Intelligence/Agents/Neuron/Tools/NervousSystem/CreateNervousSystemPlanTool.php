<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesProjectForTool;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\NervousSystem\Plan\Actions\CreatePlanAction;
use Kanvas\NervousSystem\Plan\DataTransferObject\Plan as PlanData;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * Lets the PM create a plan (an epic of work) under its project — the "turn the objective into work"
 * half of orchestration. The plan is linked to the project (Plan.project_id), so it shows up in the
 * project's open-work rollup; the project's completion is recomputed.
 */
#[AgentTool(name: 'Create Plan', category: 'nervous_system')]
class CreateNervousSystemPlanTool extends Tool implements HasRunKey
{
    use HasKanvasContext;
    use ResolvesProjectForTool;
    use TrackByInputs;

    public function __construct(
        private readonly ?Session $session = null,
    ) {
        parent::__construct(
            name: 'create_nervous_system_plan',
            description: 'Create a plan (a group of tasks / an epic) under a project. Use this to turn the '
                . 'project objective or a new request into a concrete stream of work you can then add tasks to.',
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
                name: 'project_id',
                type: PropertyType::INTEGER,
                description: 'The project this plan belongs to.',
                required: true,
            ),
            new ToolProperty(
                name: 'title',
                type: PropertyType::STRING,
                description: 'Short title of the plan / epic.',
                required: true,
            ),
            new ToolProperty(
                name: 'description',
                type: PropertyType::STRING,
                description: 'Optional detail on the goal of this plan.',
                required: false,
            ),
            new ToolProperty(
                name: 'requires_human_approval',
                type: PropertyType::BOOLEAN,
                description: 'Set true when a person must sign this off before any work starts — money '
                    . 'being spent, something sent to a customer, anything irreversible. The plan is '
                    . 'held at awaiting_approval and nothing runs until a human approves it. This is the '
                    . 'ONLY thing that actually stops the work: asking for approval in a comment does '
                    . 'not, and you cannot approve your own request.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        int $project_id,
        string $title,
        ?string $description = null,
        ?bool $requires_human_approval = null,
    ): array {
        $project = $this->resolveProjectOrError($project_id);

        if (is_array($project)) {
            return $project;
        }

        // Idempotency: repeated wakes must not recreate a plan that already exists. Reuse an open
        // plan with the same title under this project rather than duplicating it.
        $existing = Plan::query()
            ->where('project_id', $project->getId())
            ->where('title', $title)
            ->open()
            ->notDeleted()
            ->first();

        if ($existing !== null) {
            return [
                'plan_id' => $existing->getId(),
                'project_id' => $project->getId(),
                'title' => $existing->title,
                'status' => $existing->status,
                'reused' => true,
                'message' => 'This plan already exists on the project. Do NOT call create_nervous_system_plan '
                    . 'again for it — use this plan_id, or move on.',
            ];
        }

        $plan = new CreatePlanAction(
            new PlanData(
                app: $this->app,
                company: $this->company,
                title: $title,
                planType: 'project_work',
                user: $this->user,
                description: $description,
                status: PlanStatusEnum::ACTIVE,
                requiresHumanApproval: (bool) $requires_human_approval,
                createdByAgent: $this->contextAgent(),
            ),
        )->execute();

        $plan->project_id = $project->getId();
        // Where it was asked for, so the outcome can be reported back into that conversation instead
        // of only onto the plan's own Activities channel, which nobody is subscribed to.
        $plan->origin_channel_id = $this->session?->channel_id;

        // The CONVERSATION, not just the room. A chat thread is session-scoped, so a report posted to
        // the channel without the session renders outside it and is never seen (plan 26824: the alert
        // landed in the right DM and the person watching that DM saw nothing).
        $plan->origin_session_id = $this->session?->getId();

        // And by whom. `users_id` is the plan's OWNER, which on agent-created work is another agent,
        // so it is never a route to a person. The session records the human who was actually talking.
        $sessionUser = $this->session?->user;
        $plan->origin_users_id = is_array($sessionUser) && isset($sessionUser['id'])
            ? (int) $sessionUser['id']
            : null;
        $plan->saveQuietly();

        $project->recomputeCompletionPct();

        $result = [
            'plan_id' => $plan->getId(),
            'project_id' => $project->getId(),
            'title' => $plan->title,
            'status' => $plan->status,
        ];

        if ($plan->status === PlanStatusEnum::AWAITING_APPROVAL->value) {
            $result['message'] = 'This plan is held at awaiting_approval and NOTHING will run until a '
                . 'person approves it. Do not add or assign work expecting it to start, and do not '
                . 'approve it yourself. Tell the human who asked for it that it needs their sign-off.';
        }

        return $result;
    }
}
