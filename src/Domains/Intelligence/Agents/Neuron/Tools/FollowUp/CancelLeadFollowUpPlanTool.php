<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\FollowUp;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Contracts\RequiresSystemAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsLeadOwnerForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesLeadForTool;
use Kanvas\Intelligence\FollowUp\Actions\EndLeadFollowUpPlanAction;
use Kanvas\Intelligence\FollowUp\DataTransferObject\ManualFollowUpPlan;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

#[AgentTool(name: 'Cancel Lead Follow-up Plan', category: 'crm')]
class CancelLeadFollowUpPlanTool extends Tool implements RequiresSystemAgent
{
    use GuardsLeadOwnerForTool;
    use ReportsToolOutcome;
    use ResolvesLeadForTool;
    use TrackByInputs;

    protected string $name = 'cancel_lead_follow_up_plan';

    protected ?string $description = 'Stop the follow-up plan a person set on a lead with set_lead_follow_up_plan. The stage\'s '
        . 'automatic follow-up applies again afterwards; to silence that too, use set_lead_follow_up_enabled.';

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'lead_id',
                type: PropertyType::INTEGER,
                description: 'The lead whose plan to cancel.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $lead_id): array
    {
        if (! $this->hasTenantContext()) {
            return $this->tenantContextMissingError('follow-up plan');
        }

        $lead = $this->resolveLeadOrError($lead_id);
        if (is_array($lead)) {
            return $lead;
        }

        $refusal = $this->requireLeadOwnerOrAdminOrError($lead, 'cancel its follow-up plan');
        if ($refusal !== null) {
            return $refusal;
        }

        $attemptsSent = $lead->getFollowUpStateCount();
        $plan = new EndLeadFollowUpPlanAction($lead, ManualFollowUpPlan::REASON_CANCELLED)->execute();
        if ($plan === null) {
            return $this->noop(['lead_id' => $lead->getId()], guidance: 'No follow-up plan is running on this lead.');
        }

        return $this->ok([
            'lead_id' => $lead->getId(),
            'channel' => $plan->channel,
            'attempts_sent' => $attemptsSent,
            'max_attempts' => $plan->maxAttempts,
        ], guidance: 'The plan is cancelled; the stage follow-up applies again.');
    }
}
