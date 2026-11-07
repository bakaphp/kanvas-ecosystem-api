<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\FollowUp;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Contracts\RequiresSystemAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsLeadOwnerForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesLeadForTool;
use Kanvas\Intelligence\Enums\IntelligenceModeEnum;
use Kanvas\Intelligence\FollowUp\Enums\FollowUpTypeEnum;
use Kanvas\Intelligence\FollowUp\Services\LeadFollowUpEligibilityService;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * The same switch as the follow-up toggle in the lead view. Off silences the stage follow-up and
 * pauses any plan on the lead; on resumes both. It does not cancel a plan: that is
 * cancel_lead_follow_up_plan.
 */
#[AgentTool(name: 'Set Lead Follow-up Enabled', category: 'crm')]
class SetLeadFollowUpEnabledTool extends Tool implements RequiresSystemAgent
{
    use GuardsLeadOwnerForTool;
    use ReportsToolOutcome;
    use ResolvesLeadForTool;
    use TrackByInputs;

    protected string $name = 'set_lead_follow_up_enabled';

    protected ?string $description = 'Switch automatic follow-up on or off for one lead, the same switch as the toggle in the '
        . 'lead view. Off stops the stage follow-up and pauses any follow-up plan on the lead; on resumes them. '
        . 'Use it when a person says "stop following up with this lead" or "turn follow-up back on".';

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
                description: 'The lead to switch.',
                required: true,
            ),
            new ToolProperty(
                name: 'enabled',
                type: PropertyType::BOOLEAN,
                description: 'true to switch follow-up on, false to switch it off.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $lead_id, bool $enabled): array
    {
        if (! $this->hasTenantContext()) {
            return $this->tenantContextMissingError('follow-up switch');
        }

        $lead = $this->resolveLeadOrError($lead_id);
        if (is_array($lead)) {
            return $lead;
        }

        $refusal = $this->requireLeadOwnerOrAdminOrError($lead, 'switch its follow-up');
        if ($refusal !== null) {
            return $refusal;
        }

        $payload = [
            'lead_id' => $lead->getId(),
            'enabled' => $enabled,
            'plan_active' => $lead->getFollowUpPlan() !== null,
        ];

        $currentlyEnabled = ! new LeadFollowUpEligibilityService()->aiFollowUpDisabled($lead);
        if ($currentlyEnabled === $enabled) {
            return $this->noop($payload, guidance: $enabled ? 'Follow-up was already on for this lead.' : 'Follow-up was already off for this lead.');
        }

        $lead->set(
            IntelligenceModeEnum::AI_FOLLOW_UP->value,
            $enabled ? FollowUpTypeEnum::LEAD_FOLLOW_UP->value : FollowUpTypeEnum::NO_FOLLOW_UP->value,
        );
        $lead->emitLedgerEvent('lead.follow_up.toggled', payload: [
            'stage_id' => $lead->pipeline_stage_id,
            'enabled' => $enabled,
            'by_users_id' => $this->user->getId(),
        ]);

        return $this->ok($payload, guidance: $enabled
            ? 'Follow-up is on again; a paused plan resumes at the next hourly pass.'
            : ($payload['plan_active']
                ? 'Follow-up is off; the plan on this lead is paused, not cancelled, and resumes if it is switched back on.'
                : 'Follow-up is off for this lead.'));
    }
}
