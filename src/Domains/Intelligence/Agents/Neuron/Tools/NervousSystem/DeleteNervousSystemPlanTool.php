<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesPlanForTool;
use Kanvas\NervousSystem\Plan\Actions\DeletePlanAction;
use Kanvas\NervousSystem\Project\Models\Project;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * Lets the PM remove a plan that's no longer needed (superseded or created in error). Soft-deletes
 * via DeletePlanAction, which cascades to the plan's tasks, then rolls the project's completion up.
 * Prefer status=done/cancelled via update_plan when the work actually happened or was decided against.
 */
#[AgentTool(name: 'Delete Plan', category: 'nervous_system')]
class DeleteNervousSystemPlanTool extends Tool implements HasRunKey
{
    use HasKanvasContext;
    use TrackByInputs;
    use ResolvesPlanForTool;

    public function __construct()
    {
        parent::__construct(
            name: 'delete_nervous_system_plan',
            description: 'Remove a plan and its tasks (superseded or created in error). Prefer marking a '
                . 'plan done or cancelled via update_nervous_system_plan; delete only when the plan should '
                . 'not exist at all.',
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
                description: 'The plan to delete.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $plan_id): array
    {
        $plan = $this->resolvePlanOrError($plan_id, "Plan {$plan_id} was not found in this project.");

        if (is_array($plan)) {
            return $plan;
        }

        $projectId = $plan->project_id;

        $deleted = new DeletePlanAction($plan)->execute();

        if ($projectId !== null) {
            Project::query()->where('id', $projectId)->first()?->recomputeCompletionPct();
        }

        return [
            'plan_id' => $plan_id,
            'deleted' => $deleted,
        ];
    }
}
