<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Plusval\Agents;

use Kanvas\Connectors\Plusval\Services\DealsService;
use Kanvas\Connectors\Plusval\Services\PropertiesService;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Intelligence\Agents\Types\BaseAgent;
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
        return [
            Tool::make(
                'get_customer_information',
                'I can get all customer information by name. When you ask for information about any customer by name, I will call this method to retrieve their deals with the complete profile for the customer and the agent.',
            )->addProperty(
                new ToolProperty(
                    name: 'customerName',
                    type: PropertyType::STRING,
                    description: 'The name of the customer to retrieve information for. This should be the full name of the customer, e.g., "Juan Perez".',
                    required: true
                )
            )->setCallable(function (string $customerName) {
                $agentPhone = $this->getAgentPhone();
                if (empty($agentPhone)) {
                    return [
                        'status' => 'error',
                        'message' => 'No phone number found for agent. Please add a phone number to your profile.',
                        'deals' => [],
                    ];
                }

                try {
                    // Initialize the Plusval deals service
                    $dealsService = new DealsService($this->app, $this->entity->company);

                    // Search for deals using agent phone and customer name
                    $response = $dealsService->getDealsByAgentPhoneAndCustomerName($agentPhone, $customerName);

                    // Process the API response
                    if (isset($response['results']['deals']) && ! empty($response['results']['deals'])) {
                        $deals = $response['results']['deals'];

                        return [
                            'status' => 'success',
                            'message' => 'Found ' . count($deals) . " deal(s) for customer: {$customerName}",
                            'agent_phone' => $agentPhone,
                            'customer_name' => $customerName,
                            'total_deals' => count($deals),
                            'deals' => $this->formatDeals($deals),
                        ];
                    } else {
                        return [
                            'status' => 'no_results',
                            'message' => "No deals found for customer: {$customerName}",
                            'agent_phone' => $agentPhone,
                            'customer_name' => $customerName,
                            'total_deals' => 0,
                            'deals' => [],
                        ];
                    }
                } catch (\Exception $e) {
                    return [
                        'status' => 'error',
                        'message' => 'Error retrieving deals: ' . $e->getMessage(),
                        'agent_phone' => $agentPhone,
                        'customer_name' => $customerName,
                        'deals' => [],
                    ];
                }
            }),

            Tool::make(
                'get_properties_information',
                'I can get properties by id, name, title, address, description, notes or owner name. When you ask for properties, I will call this method to retrieve properties based on the criteria you provide.'
            )->addProperty(
                new ToolProperty(
                    name: 'criteria',
                    type: PropertyType::STRING,
                    description: 'The criteria to filter properties. This can be a property ID, name, title, address, description, notes or owner name.',
                    required: true
                )
            )->setCallable(function (string $criteria) {
                $agentPhone = $this->getAgentPhone();
                if (empty($agentPhone)) {
                    return [
                        'status' => 'error',
                        'message' => 'No phone number found for agent. Please add a phone number to your profile.',
                        'properties' => [],
                    ];
                }

                try {
                    // Initialize the Plusval properties service
                    $propertiesService = new PropertiesService($this->app, $this->entity->company);

                    // Search for properties using agent phone and criteria
                    $response = $propertiesService->getPropertiesByAgentAndCriteria($agentPhone, $criteria);

                    // Process the API response
                    if (isset($response['results']['properties']) && ! empty($response['results']['properties'])) {
                        $properties = $response['results']['properties'];

                        return [
                            'status' => 'success',
                            'message' => 'Found ' . count($properties) . " property(ies) for criteria: {$criteria}",
                            'agent_phone' => $agentPhone,
                            'criteria' => $criteria,
                            'total_properties' => count($properties),
                            'properties' => $this->formatProperties($properties),
                        ];
                    } else {
                        return [
                            'status' => 'no_results',
                            'message' => "No properties found for criteria: {$criteria}",
                            'agent_phone' => $agentPhone,
                            'criteria' => $criteria,
                            'total_properties' => 0,
                            'properties' => [],
                        ];
                    }
                } catch (\Exception $e) {
                    return [
                        'status' => 'error',
                        'message' => 'Error retrieving properties: ' . $e->getMessage(),
                        'agent_phone' => $agentPhone,
                        'criteria' => $criteria,
                        'properties' => [],
                    ];
                }
            }),
        ];
    }

    public function getAgentPhone(): string
    {
        /** @var People $agent */
        $agent = $this->entity;

        // Get agent's phone number (the person using the agent)
        $agentPhones = $agent->getPhones()->pluck('value')->toArray();
        $agentCellPhones = $agent->getCellPhones()->pluck('value')->toArray();
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
