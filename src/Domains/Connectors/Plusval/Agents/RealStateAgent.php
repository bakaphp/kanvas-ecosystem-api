<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Plusval\Agents;

use Kanvas\Connectors\Plusval\Enums\ConfigurationEnum;
use Kanvas\Connectors\Plusval\Helpers\PhoneHelper;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Neuron\BaseRagAgent;
use NeuronAI\MCP\McpConnector;
use Override;

class RealStateAgent extends BaseRagAgent
{
    #[Override]
    protected function tools(): array
    {
        $baseUrl = $this->app?->get(ConfigurationEnum::BASE_URL->value);
        $apiKey = $this->app?->get(ConfigurationEnum::API_KEY->value);
        $senderPhone = PhoneHelper::formatPhoneNumber($this->getSenderPhone());

        return [
            ...McpConnector::make([
                'url' => $baseUrl . '/mcp/plusval',
                'token' => 'BEARER_TOKEN',
                'timeout' => 30,
                'headers' => [
                    'x-api-key' => $apiKey,
                    'x-sender-phone' => $senderPhone,
                ],
            ])->tools(),
        ];
    }

    /**
     * A Plusval agent's persona lives on the agent record or its type (soul/instructions/output_format),
     * not in the structured `role` the generic prompt is built from.
     */
    #[Override]
    public function instructions(): string
    {
        $persona = $this->agent?->personaPrompt() ?? '';

        return $persona === '' ? parent::instructions() : $persona . "\n\n" . $this->platformContextBlock();
    }

    public function getSenderPhone(): string
    {
        /** @var People|Lead $entity */
        $entity = $this->entity;
        $person = $entity instanceof Lead ? $entity->people : $entity;

        return (string) ($person->getPhones()->merge($person->getCellPhones())->pluck('value')->unique()->first() ?? '');
    }
}
