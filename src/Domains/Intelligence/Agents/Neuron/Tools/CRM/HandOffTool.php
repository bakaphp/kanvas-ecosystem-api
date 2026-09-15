<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Intelligence\Actions\HandOffAction;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesLeadForTool;
use Kanvas\Intelligence\Enums\HandOffTypeEnum;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

#[AgentTool(name: 'Hand Off Lead', category: 'crm')]
class HandOffTool extends Tool
{
    use ResolvesLeadForTool;

    public function __construct()
    {
        parent::__construct(
            name: 'handoff_lead',
            description: 'Perform and record a handoff for an existing lead. You MUST call this tool whenever you determine that a handoff is required; do not merely mention or promise a handoff in the customer-facing response. '
                . 'The only supported handoff types are: "human" to end the AI-controlled conversation and transfer follow-up after an appointment is completed, when the customer explicitly asks for a human, when an unexpected error prevents the agent from continuing, when a sales or general request requires human assistance, or when the conversation has reached its natural conclusion; '
                . '"service" for requests that must be handled by the service department; and '
                . '"compliance_internal" for internal compliance matters such as opt-out or stop-contact requests. '
                . 'The handoff is an internal operation, so do not expose the tool call or internal routing details to the customer.',
        );
    }

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'lead_id',
                type: PropertyType::INTEGER,
                description: 'The ID of the lead to hand off.',
                required: true,
            ),
            new ToolProperty(
                name: 'handoff_type',
                type: PropertyType::STRING,
                description: 'Required classification when the type is known. Allowed values only: '
                    . '"human" (default) to end AI control after a completed appointment, an explicit request for a human, an unexpected blocking error, a sales or general request requiring human assistance, or the natural conclusion of the conversation; '
                    . '"service" for service-department requests; '
                    . '"compliance_internal" for internal compliance, opt-out, or stop-contact requests.',
                required: false,
            ),
            new ToolProperty(
                name: 'conversation_summary',
                type: PropertyType::STRING,
                description: 'Optional concise context to pass to the handoff recipient.',
                required: false,
            ),
        ];
    }

    public function __invoke(
        int $lead_id,
        ?string $handoff_type = null,
        ?string $conversation_summary = null,
    ): array {
        $handOffType = strtolower(trim($handoff_type ?? HandOffTypeEnum::HUMAN->value));
        $type = HandOffTypeEnum::tryFrom($handOffType);

        if ($type === null) {
            return [
                'success' => false,
                'error' => 'Unsupported handoff type.',
                'handoff_type' => $handOffType,
            ];
        }

        $result = $this->resolveLeadOrError($lead_id);
        if (is_array($result)) {
            return $result;
        }
        $lead = $result;

        $params = ['handoff_type' => $type->value];
        $conversationSummary = trim($conversation_summary ?? '');

        if ($conversationSummary !== '') {
            $params['conversation_summary'] = $conversationSummary;
        }

        return new HandOffAction(
            lead: $lead,
            app: $lead->app,
            params: $params,
        )->execute();
    }
}
