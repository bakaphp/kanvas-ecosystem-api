<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Plusval\Services;

use GuzzleHttp\Exception\GuzzleException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Plusval\Client;
use Kanvas\Exceptions\ValidationException;

class DealsService
{
    protected Client $client;

    public function __construct(
        protected Apps $app,
        protected Companies $company
    ) {
        $this->client = new Client($app, $company);
    }

    /**
    * Get deals by agent phone number and customer name
    *
    * @param string $agentPhone Agent's phone number (e.g., "+1 809-864-6241")
    * @param string $customerName Customer name (e.g., "hector baba")
    * @throws GuzzleException
    * @throws ValidationException
    */
    public function getDealsByAgentPhoneAndCustomerName(string $agentPhone, string $customerName): array
    {
        if (empty($agentPhone)) {
            throw new ValidationException('Agent phone number is required');
        }

        if (empty($customerName)) {
            throw new ValidationException('Customer name is required');
        }

        // Clean and format phone number if needed
        $agentPhone = $this->formatPhoneNumber($agentPhone);

        return $this->client->getDeals($agentPhone, $customerName);
    }

    /**
     * Get deals by agent phone number only
     *
     * @param string $agentPhone Agent's phone number
     * @throws GuzzleException
     * @throws ValidationException
     */
    public function getDealsByAgentPhone(string $agentPhone): array
    {
        if (empty($agentPhone)) {
            throw new ValidationException('Agent phone number is required');
        }

        $agentPhone = $this->formatPhoneNumber($agentPhone);

        return $this->client->get('api/v2/ai/deals', ['phone' => $agentPhone]);
    }

    /**
     * Get deals by customer name only
     *
     * @param string $customerName Customer name
     * @throws GuzzleException
     * @throws ValidationException
     */
    public function getDealsByCustomerName(string $customerName): array
    {
        if (empty($customerName)) {
            throw new ValidationException('Customer name is required');
        }

        return $this->client->get('api/v2/ai/deals', ['name' => $customerName]);
    }

    /**
     * Format agent phone number for API consumption
     */
    protected function formatPhoneNumber(string $agentPhone): string
    {
        // Remove any extra spaces and ensure proper formatting
        $agentPhone = trim($agentPhone);

        // If phone doesn't start with +, assume it's a Dominican number and add +1
        if (! str_starts_with($agentPhone, '+')) {
            // Remove any non-numeric characters except + and -
            $cleanPhone = preg_replace('/[^0-9\-]/', '', $agentPhone);

            // If it starts with 809, 829, or 849 (Dominican area codes), add +1
            if (preg_match('/^(809|829|849)/', $cleanPhone)) {
                $agentPhone = '+1 ' . $cleanPhone;
            } else {
                $agentPhone = '+' . $cleanPhone;
            }
        }

        return $agentPhone;
    }

    /**
     * Search deals with multiple criteria
     *
     * @throws GuzzleException
     */
    public function searchDeals(array $criteria): array
    {
        $validCriteria = [];

        if (! empty($criteria['agent_phone'])) {
            $validCriteria['phone'] = $this->formatPhoneNumber($criteria['agent_phone']);
        }

        if (! empty($criteria['customer_name'])) {
            $validCriteria['name'] = trim($criteria['customer_name']);
        }

        // Legacy support for 'phone' and 'name' keys
        if (! empty($criteria['phone'])) {
            $validCriteria['phone'] = $this->formatPhoneNumber($criteria['phone']);
        }

        if (! empty($criteria['name'])) {
            $validCriteria['name'] = trim($criteria['name']);
        }

        if (empty($validCriteria)) {
            throw new ValidationException('At least one search criteria (agent_phone or customer_name) is required');
        }

        return $this->client->get('api/v2/ai/deals', $validCriteria);
    }
}
