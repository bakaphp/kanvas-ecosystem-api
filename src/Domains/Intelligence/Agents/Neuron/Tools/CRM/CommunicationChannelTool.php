<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Guild\Leads\Enums\ConfigurationEnum as LeadsEnumsConfigurationEnum;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesLeadForTool;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

#[AgentTool(name: 'Communication Channel')]
class CommunicationChannelTool extends Tool
{
    use ResolvesLeadForTool;

    public function __construct(
    ) {
        parent::__construct(
            name: 'get_communication_channel',
            description: 'Get the selected communication channel and contact information (email, phone) for the lead.',
        );
    }

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'lead_id',
                type: PropertyType::INTEGER,
                description: 'The ID of the lead provided in the conversation context.',
                required: true,
            ),
        ];
    }

    public function __invoke(int $lead_id): array
    {
        $result = $this->resolveLeadOrError($lead_id);
        if (is_array($result)) {
            return $result;
        }
        $lead = $result;

        return [
            'selected_channel' => $lead->get(LeadsEnumsConfigurationEnum::AGENT_COMMUNICATION_CHANNEL->value),
            'available_channels' => [
                'email',
                'sms',
                'whatsapp',
            ],
            'selection_reason' => '',
            'contact' => [
                'email' => $lead->people->getEmails()->first()?->value,
                'phone_e164' => $lead->people->getPhones()->first()?->value,
            ],
        ];
    }
}
