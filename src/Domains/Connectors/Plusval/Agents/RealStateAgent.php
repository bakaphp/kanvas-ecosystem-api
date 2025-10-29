<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Plusval\Agents;

use Kanvas\Connectors\Plusval\Enums\ConfigurationEnum;
use Kanvas\Connectors\Plusval\Helpers\PhoneHelper;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Types\BaseAgent;
use NeuronAI\MCP\McpConnector;
use Override;

class RealStateAgent extends BaseAgent
{
    #[Override]
    protected function tools(): array
    {
        /** @psalm-suppress MixedReturnTypeCoercion */
        $baseUrl = $this->app->get(ConfigurationEnum::BASE_URL->value);
        $apiKey = $this->app->get(ConfigurationEnum::API_KEY->value);
        $senderPhone = PhoneHelper::formatPhoneNumber($this->getSenderPhone());
        return [
            ...McpConnector::make([
                'url' => $baseUrl . '/mcp/plusval',
                'token' => 'BEARER_TOKEN',
                'timeout' => 30,
                'headers' => [
                    'x-api-key' => $apiKey,
                    'x-sender-phone' => $senderPhone
                ]
            ])->tools(),
        ];
    }

    public function getSenderPhone(): string
    {
        /** @var People|Lead $agent */
        $agent = $this->entity;

        // Get agent's phone number (the person using the agent)
        $agentPhones = $agent instanceof Lead ? $agent->people->getPhones()->pluck('value')->toArray() : $agent->getPhones()->pluck('value')->toArray();
        $agentCellPhones = $agent instanceof Lead ? $agent->people->getCellPhones()->pluck('value')->toArray() : $agent->getCellPhones()->pluck('value')->toArray();
        $allAgentPhones = array_unique(array_merge($agentPhones, $agentCellPhones));

        if (empty($allAgentPhones)) {
            return '';
        }

        // Use the first phone number found
        $agentPhone = $allAgentPhones[0];

        return $agentPhone;
    }
}
