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
            description: 'Hand off an existing lead. The agent instructions determine when a handoff is appropriate and which handoff type to use; call this tool only with a handoff type allowed by those instructions.',
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
                description: 'The handoff type selected from the options allowed by the agent instructions: "human" (default), "service", or "compliance_internal".',
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
