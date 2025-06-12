<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Plusval\Agents;

use Kanvas\Connectors\Plusval\Services\DealsService;
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
                'I can get all customer information by name. When you ask for information about any customer by name, I will call this method to retrieve their complete profile and deal history.',
            )->addProperty(
                new ToolProperty(
                    name: 'customerName',
                    type: PropertyType::STRING,
                    description: 'The name of the customer to retrieve information for. This should be the full name of the customer, e.g., "Hector Baba".',
                    required: true
                )
            )
            ->setCallable(function (string $customerName) {
                /** @var People $agent */
                $agent = $this->entity;

                // Get agent's phone number (the person using the agent)
                $agentPhones = $agent->getPhones()->pluck('value')->toArray();
                $agentCellPhones = $agent->getCellPhones()->pluck('value')->toArray();
                $allAgentPhones = array_unique(array_merge($agentPhones, $agentCellPhones));

                if (empty($allAgentPhones)) {
                    return [
                        'status' => 'error',
                        'message' => 'No phone number found for agent. Please add a phone number to your profile.',
                        'deals' => [],
                    ];
                }

                // Use the first phone number found
                $agentPhone = $allAgentPhones[0];

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
        ];
    }

    /**
     * Format deals data for better readability
     */
    private function formatDeals(array $deals): array
    {
        return array_map(function ($deal) {
            $client = $deal['client'] ?? [];

            return [
                'deal_id' => $deal['id'],
                'deal_name' => $deal['name'],
                'deal_status' => $deal['status'],
                'deal_rating' => $deal['rating'],
                'created_date' => $deal['created_at'],
                'last_updated' => $deal['updated_at'],
                'notes' => $deal['notes'],
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
}
