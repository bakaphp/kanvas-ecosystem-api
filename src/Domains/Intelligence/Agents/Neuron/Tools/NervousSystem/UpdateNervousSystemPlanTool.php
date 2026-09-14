<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesPlanForTool;
use Kanvas\NervousSystem\Plan\Actions\UpdatePlanAction;
use Kanvas\NervousSystem\Plan\DataTransferObject\Plan as PlanData;
use Kanvas\NervousSystem\Plan\Enums\PlanBlockedNeedsEnum;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * Lets the PM update a plan — retitle it, re-scope its description, reprioritize, or change its
 * status (use status=done to COMPLETE a plan, blocked to flag it stuck). Wraps UpdatePlanAction and
 * rolls the project's completion up.
 */
#[AgentTool(name: 'Update Plan', category: 'nervous_system')]
class UpdateNervousSystemPlanTool extends Tool implements HasRunKey
{
    use HasKanvasContext;
    use TrackByInputs;
    use ResolvesPlanForTool;

    public function __construct()
    {
        parent::__construct(
            name: 'update_nervous_system_plan',
            description: 'Update a plan: its title, description, priority, or status. Set status=done to '
                . 'complete the plan, blocked when it is stuck, cancelled to drop it. Only pass the fields '
                . 'you want to change.',
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
                description: 'The plan to update.',
                required: true,
            ),
            new ToolProperty(
                name: 'title',
                type: PropertyType::STRING,
                description: 'New title (optional).',
                required: false,
            ),
            new ToolProperty(
                name: 'description',
                type: PropertyType::STRING,
                description: 'New description (optional).',
                required: false,
            ),
            new ToolProperty(
                name: 'status',
                type: PropertyType::STRING,
                description: 'New status: draft | active | blocked | done | cancelled (optional).',
                required: false,
            ),
            new ToolProperty(
                name: 'priority',
                type: PropertyType::INTEGER,
                description: 'New priority, higher = more important (optional).',
                required: false,
            ),
            new ToolProperty(
                name: 'blocked_needs',
                type: PropertyType::STRING,
                description: 'Only with status=blocked. Say WHO can unblock it: "human" when a person '
                    . 'has to answer — an approval, a decision, information only they have — or '
                    . '"capability" when a tool, integration or permission is missing. A "human" block '
                    . 'interrupts the person who asked for the work, in their own conversation; a '
                    . '"capability" block goes to the board for an operator. Choose honestly: marking a '
                    . 'missing tool as "human" pesters someone who cannot help.',
                required: false,
                enum: ['human', 'capability'],
            ),
            new ToolProperty(
                name: 'requires_human_approval',
                type: PropertyType::BOOLEAN,
                description: 'Set true to HOLD the plan for a person to sign off — money being spent, '
                    . 'something sent to a customer, anything irreversible. The plan moves to '
                    . 'awaiting_approval and nothing runs until a human approves it. This is the only '
                    . 'thing that actually stops the work: setting status=blocked or asking in a comment '
                    . 'does not, and you cannot approve your own request.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        int $plan_id,
        ?string $title = null,
        ?string $description = null,
        ?string $status = null,
        ?int $priority = null,
        ?bool $requires_human_approval = null,
        ?string $blocked_needs = null,
    ): array {
        $plan = $this->resolvePlanOrError($plan_id, "Plan {$plan_id} was not found in this project.");

        if (is_array($plan)) {
            return $plan;
        }

        $data = [];
        if ($title !== null) {
            $data['title'] = $title;
        }
        if ($description !== null) {
            $data['description'] = $description;
        }
        if ($status !== null) {
            $data['status'] = $status;
        }
        if ($priority !== null) {
            $data['priority'] = $priority;
        }
        if ($requires_human_approval !== null) {
            $data['requires_human_approval'] = $requires_human_approval;
        }
        if ($blocked_needs !== null && PlanBlockedNeedsEnum::tryFrom($blocked_needs) !== null) {
            $data['blocked_needs'] = $blocked_needs;
        }

        $updated = new UpdatePlanAction(
            $plan,
            PlanData::forUpdate(
                $plan,
                $this->app,
                $this->company,
                $data
            ),
        )->execute();

        $updated->project?->recomputeCompletionPct();

        $result = [
            'plan_id' => $updated->getId(),
            'title' => $updated->title,
            'status' => $updated->status,
            'completion_pct' => $updated->completion_pct,
        ];

        if ($updated->status === PlanStatusEnum::AWAITING_APPROVAL->value) {
            $result['message'] = 'This plan is now held at awaiting_approval and NOTHING will run until '
                . 'a person approves it. Do not approve it yourself — say plainly what you need signed '
                . 'off and who you are asking.';
        }

        return $result;
    }
}
