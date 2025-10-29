<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Plusval\Agents;

use Kanvas\Connectors\Plusval\Services\DealsService;
use Kanvas\Connectors\Plusval\Services\ProfileService;
use Kanvas\Connectors\Plusval\Services\PropertiesService;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Types\BaseAgent;
use NeuronAI\MCP\McpConnector;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

class RealStateAgent extends BaseAgent
{
    #[Override]
    protected function tools(): array
    {
        /** @psalm-suppress MixedReturnTypeCoercion */
        $baseUrl = $this->app->get(ConfigurationEnum::BASE_URL->value);
        $apiKey = $this->app->get(ConfigurationEnum::API_KEY->value);
        return [
            ...McpConnector::make([
                'url' => $baseUrl . '/mcp/plusval',
                'token' => 'BEARER_TOKEN',
                'timeout' => 30,
                'headers' => [
                    'x-api-key' => $apiKey
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

    /**
     * Format deals data for better readability
     */
    private function formatDeals(array $deals): array
    {
        return array_map(function ($deal) {
            $client = $deal['client'] ?? [];
            $user = $deal['user'] ?? [];

            return [
                'deal_id' => $deal['id'],
                'deal_name' => $deal['name'],
                'deal_status' => $deal['status'],
                'deal_rating' => $deal['rating'],
                'deal_logs' => $deal['events'] ?? [],
                'created_date' => $deal['created_at'],
                'last_updated' => $deal['updated_at'],
                'notes' => $deal['notes'],
                'user' => [
                    'id' => $user['id'] ?? null,
                    'first_name' => $user['name'] ?? null,
                    'last_name' => $user['lastname'] ?? null,
                    'cellphone' => $user['celphone'] ?? null,
                    'email' => $user['email'] ?? null,
                    'position' => $user['position'] ?? null,
                ],
                'client' => [
                    'name' => $client['fullname'] ?? null,
                    'email' => $client['email'] ?? null,
                    'cellphone' => $client['celphone'] ?? null,
                    'phone' => $client['phone'] ?? null,
                    'address' => $client['address'] ?? null,
                    'birth_date' => $client['birth_date'] ?? null,
                    'gender' => $client['genre'] ?? null,
                    'age_range' => $client['age_range'] ?? null,
                    'client_type' => $client['client_type'] ?? null,
                    'property_interest' => $client['finding'] ?? null,
                    'price_range' => $client['price'] ?? null,
                    'estimated_value' => $client['value'] ?? null,
                    'contact_frequency_days' => $client['contact_days'] ?? null,
                    'is_business' => $client['is_business'] ?? false,
                    'business_name' => $client['business_name'] ?? null,
                ],
            ];
        }, $deals);
    }

    /**
     * Format properties data for better readability
     */
    private function formatProperties(array $properties): array
    {
        return array_map(function ($property) {
            $client = $property['client'] ?? [];
            $user = $property['user'] ?? [];
            $city = $property['city'] ?? [];
            $sector = $property['sector'] ?? [];
            $propertyType = $property['property_type'] ?? [];
            $action = $property['action'] ?? [];

            return [
                'property_id' => $property['id'],
                'property_action' => $action['name'] ?? null,
                'property_name' => $property['name'],
                'property_title' => $property['title'],
                'property_subtitle' => $property['subtitle'],
                'property_description' => $property['description'],
                'property_address' => $property['address'],
                'property_red_buttons' => $property['red_buttons'],
                'property_price_dop' => $property['price'],
                'property_price_usd' => $property['priceus'],
                'property_bedrooms' => $property['bedrooms'],
                'property_bathrooms' => $property['bathrooms'],
                'property_parking' => $property['parking'],
                'property_meters' => $property['meters'],
                'property_city' => $city['name'] ?? null,
                'property_sector' => $sector['name'] ?? null,
                'property_type' => $propertyType['description'] ?? null,
                'created_date' => $property['created_at'],
                'notes' => $property['notes'],
                'user' => [
                    'id' => $user['id'] ?? null,
                    'first_name' => $user['name'] ?? null,
                    'last_name' => $user['lastname'] ?? null,
                    'cellphone' => $user['celphone'] ?? null,
                    'email' => $user['email'] ?? null,
                    'position' => $user['position'] ?? null,
                ],
                'owner' => [
                    'name' => $client['fullname'] ?? null,
                    'email' => $client['email'] ?? null,
                    'cellphone' => $client['celphone'] ?? null,
                    'phone' => $client['phone'] ?? null,
                    'address' => $client['address'] ?? null,
                    'birth_date' => $client['birth_date'] ?? null,
                    'gender' => $client['genre'] ?? null,
                    'age_range' => $client['age_range'] ?? null,
                    'client_type' => $client['client_type'] ?? null,
                    'property_interest' => $client['finding'] ?? null,
                    'price_range' => $client['price'] ?? null,
                    'estimated_value' => $client['value'] ?? null,
                    'contact_frequency_days' => $client['contact_days'] ?? null,
                    'is_business' => $client['is_business'] ?? false,
                    'business_name' => $client['business_name'] ?? null,
                ],
            ];
        }, $properties);
    }
}
