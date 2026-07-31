<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesPlanForTool;
use Kanvas\NervousSystem\Plan\Actions\UpdatePlanAction;
use Kanvas\NervousSystem\Plan\DataTransferObject\Plan as PlanData;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * Lets the PM update a plan — retitle it, re-scope its description, reprioritize, or change its
 * status (use status=done to COMPLETE a plan, blocked to flag it stuck). Wraps UpdatePlanAction and
 * rolls the project's completion up.
 */
#[AgentTool(name: 'Update Plan', category: 'nervous_system')]
class UpdateNervousSystemPlanTool extends Tool
{
    use HasKanvasContext;
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

        return [
            'plan_id' => $updated->getId(),
            'title' => $updated->title,
            'status' => $updated->status,
            'completion_pct' => $updated->completion_pct,
        ];
    }
}
