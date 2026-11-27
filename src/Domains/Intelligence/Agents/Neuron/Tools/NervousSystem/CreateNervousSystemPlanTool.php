<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem;

use Closure;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Concerns\HasConversationHuman;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesProjectForTool;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\NervousSystem\Plan\Actions\CreatePlanAction;
use Kanvas\NervousSystem\Plan\DataTransferObject\Plan as PlanData;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\Users\Models\Users;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/**
 * Lets the PM create a plan (an epic of work) under its project — the "turn the objective into work"
 * half of orchestration. The plan is linked to the project (Plan.project_id), so it shows up in the
 * project's open-work rollup; the project's completion is recomputed.
 */
#[AgentTool(name: 'Create Plan', category: 'nervous_system')]
class CreateNervousSystemPlanTool extends Tool
{
    use HasConversationHuman;
    use HasKanvasContext;
    use ResolvesProjectForTool;
    use TrackByInputs;

    protected string $name = 'create_nervous_system_plan';

    protected ?string $description = 'Create a plan (a group of tasks / an epic) under a project. Use this to turn the '
        . 'project objective or a new request into a concrete stream of work you can then add tasks to.';

    /**
     * @param (Closure(): Project)|null $defaultProject where a plan with no project_id goes. Given by an
     *        agent that hands work out on a board of its own; without it project_id stays required.
     */
    public function __construct(
        private readonly ?Session $session = null,
        private readonly ?Closure $defaultProject = null,
    ) {
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
                description: $this->defaultProject === null
                    ? 'The project this plan belongs to.'
                    : 'Only when the work clearly belongs to an existing project. Leave it out for everything else.',
                required: $this->defaultProject === null,
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
        string $title,
        ?int $project_id = null,
        ?string $description = null,
        ?bool $requires_human_approval = null,
    ): array {
        if ($this->defaultProject !== null && ! $this->canReportBack()) {
            return [
                'error' => 'I cannot tell who is asking or where to answer them from here, so the work would '
                    . 'have nowhere to report back. Nothing was created. Ask the person to request it in a '
                    . 'conversation with you.',
            ];
        }

        $project = $this->projectFor($project_id);

        if (is_array($project)) {
            return $project;
        }

        // Idempotency: repeated wakes must not recreate a plan that already exists.
        $existing = $this->reusablePlan($project, $title);

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
                requiresHumanApproval: (bool) $requires_human_approval,
                createdByAgent: $this->contextAgent(),
            ),
        )->execute();

        $plan->project_id = $project->getId();
        // The CONVERSATION, not just the room (the alert landed in the right DM, outside the
        // session, and the person watching that DM saw nothing).
        $plan->rememberOrigin($this->session, $this->asker());

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

    /**
     * @return Project|array<string, string>
     */
    private function projectFor(?int $projectId): Project|array
    {
        // A model fills an optional id it has no value for with 0, and no row has id 0.
        if ($projectId !== null && $projectId > 0) {
            // A project_id that does not resolve is an error, never a reason to use the default.
            return $this->resolveProjectOrError($projectId);
        }

        if ($this->defaultProject === null) {
            return ['error' => 'project_id is required: pass the id of the project this plan belongs to.'];
        }

        try {
            return ($this->defaultProject)();
        } catch (Throwable $e) {
            report($e);

            return ['error' => 'There is no board to put this plan on: ' . $e->getMessage()];
        }
    }

    /**
     * On a board shared by a whole company, a title says nothing about who asked: "Prepare an invoice"
     * from two people is two plans, each answered in its own conversation. So with a default board a
     * plan is reused only inside one person's conversation with the agent that created it.
     */
    private function reusablePlan(Project $project, string $title): ?Plan
    {
        $query = Plan::query()
            ->where('project_id', $project->getId())
            ->where('title', $title)
            ->open()
            ->notDeleted();

        if ($this->defaultProject === null) {
            return $query->first();
        }

        // canReportBack() already answered for the asker and the session.
        $creator = $this->contextAgent();

        if ($creator === null) {
            return null;
        }

        return $query
            ->where('created_by_agent_id', $creator->getId())
            ->where('origin_session_id', $this->session?->getId())
            ->where('origin_users_id', $this->asker()?->getId())
            ->first();
    }

    /**
     * Work handed off for someone has to come back to them: a known person, in a conversation that has
     * a channel to post the outcome on.
     */
    private function canReportBack(): bool
    {
        return $this->asker() !== null && $this->session?->channel_id !== null;
    }

    /**
     * Only an agent with a board of its own carries the asker; everywhere else the session says who.
     */
    private function asker(): ?Users
    {
        return $this->defaultProject !== null ? $this->conversationHuman : null;
    }
}
