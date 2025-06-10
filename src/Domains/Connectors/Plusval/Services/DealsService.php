<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Plusval\Services;

use Baka\Support\Str;
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
     * Required format: +1 809-864-6241
     */
    protected function formatPhoneNumber(string $agentPhone): string
    {
        // Remove any extra spaces and non-numeric characters except + and -
        $agentPhone = Str::trim($agentPhone);
        $cleanPhone = Str::replaceMatches('/[^0-9]/', '', $agentPhone);

        // If we have exactly 10 digits, assume it's a Dominican number without country code
        if (Str::length($cleanPhone) === 10) {
            // Check if it starts with Dominican area codes (809, 829, 849)
            if (Str::startsWith($cleanPhone, ['809', '829', '849'])) {
                $areaCode = Str::substr($cleanPhone, 0, 3);
                $firstPart = Str::substr($cleanPhone, 3, 3);
                $lastPart = Str::substr($cleanPhone, 6, 4);

                return "+1 {$areaCode}-{$firstPart}-{$lastPart}";
            }
        }

        // If we have 11 digits and starts with 1, assume it's US/Dominican with country code
        if (Str::length($cleanPhone) === 11 && Str::startsWith($cleanPhone, '1')) {
            $areaCode = Str::substr($cleanPhone, 1, 3);
            $firstPart = Str::substr($cleanPhone, 4, 3);
            $lastPart = Str::substr($cleanPhone, 7, 4);

            return "+1 {$areaCode}-{$firstPart}-{$lastPart}";
        }

        // If phone already has proper format (+1 XXX-XXX-XXXX), return as is
        if (Str::isMatch('/^\+1\s\d{3}-\d{3}-\d{4}$/', $agentPhone)) {
            return $agentPhone;
        }

        // If phone starts with +1 but wrong format, reformat
        if (Str::startsWith($agentPhone, '+1')) {
            $phoneOnly = Str::replaceMatches('/[^0-9]/', '', Str::substr($agentPhone, 2));
            if (Str::length($phoneOnly) === 10) {
                $areaCode = Str::substr($phoneOnly, 0, 3);
                $firstPart = Str::substr($phoneOnly, 3, 3);
                $lastPart = Str::substr($phoneOnly, 6, 4);

                return "+1 {$areaCode}-{$firstPart}-{$lastPart}";
            }
        }

        // For any other format, try to extract 10 digits and format as Dominican
        if (Str::length($cleanPhone) >= 10) {
            $last10 = Str::substr($cleanPhone, -10);
            $areaCode = Str::substr($last10, 0, 3);
            $firstPart = Str::substr($last10, 3, 3);
            $lastPart = Str::substr($last10, 6, 4);

            return "+1 {$areaCode}-{$firstPart}-{$lastPart}";
        }

        // If all else fails, return original with +1 prefix if missing
        if (! Str::startsWith($agentPhone, '+1')) {
            return '+1 ' . $agentPhone;
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
