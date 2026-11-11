<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\FollowUp;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Contracts\RequiresSystemAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesLeadForTool;
use Kanvas\Intelligence\FollowUp\Services\LeadFollowUpPlanService;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

#[AgentTool(name: 'Get Lead Follow-up Plan', category: 'crm')]
class GetLeadFollowUpPlanTool extends Tool implements RequiresSystemAgent
{
    use ReportsToolOutcome;
    use ResolvesLeadForTool;
    use TrackByInputs;

    protected string $name = 'get_lead_follow_up_plan';

    protected ?string $description = 'Read the follow-up plan a person set on a lead: channel, text, cadence, attempts sent so '
        . 'far, when the next message is due, and whether it is paused (follow-up switched off, AI off) or already '
        . 'ended and why. Use it before changing a plan or when asked "is the follow-up running on this lead".';

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
                description: 'The lead to look at.',
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

        $lead = $this->resolveLeadInReadScopeOrError($lead_id);
        if (is_array($lead)) {
            return $lead;
        }

        $status = new LeadFollowUpPlanService()->status($lead);
        if ($status === null) {
            return $this->notFound(['lead_id' => $lead->getId()], guidance: 'No follow-up plan was ever set on this lead.');
        }

        $guidance = match (true) {
            ! $status['is_active'] => sprintf('The last plan ended (%s) after %d of %d messages.', $status['done_reason'], $status['attempts_sent'], $status['max_attempts']),
            $status['paused_reason'] !== null => sprintf('The plan is paused (%s): %d of %d sent. It resumes when that is switched back on.', $status['paused_reason'], $status['attempts_sent'], $status['max_attempts']),
            default => sprintf('The plan is running: %d of %d sent, next one due %s.', $status['attempts_sent'], $status['max_attempts'], $status['next_due_at']),
        };

        return $this->ok(['lead_id' => $lead->getId(), ...$status], guidance: $guidance);
    }
}
