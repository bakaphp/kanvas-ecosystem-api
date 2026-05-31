<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Intelligence\Actions\HandOffAction;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesLeadForTool;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

#[AgentTool(name: 'Hand Off Lead')]
class HandOffTool extends Tool
{
    use ResolvesLeadForTool;

    public function __construct()
    {
        parent::__construct(
            name: 'handoff_lead',
            description: 'Transfer the lead to a human agent. '
                . 'Call this when the lead requests to speak with a human, '
                . 'when the conversation requires human intervention, '
                . 'or when the AI cannot resolve the lead\'s request.',
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
                description: 'The type of handoff: "human" (default), "service", or "compliance_internal".',
                required: false,
            ),
        ];
    }

    public function __invoke(int $lead_id, string $handoff_type = 'human'): array
    {
        $result = $this->resolveLeadOrError($lead_id);
        if (is_array($result)) {
            return $result;
        }
        $lead = $result;

        return new HandOffAction(
            lead: $lead,
            app: $lead->app,
            params: ['handoff_type' => $handoff_type],
        )->execute();
    }
}
