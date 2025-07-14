<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Plusval\Services;

use GuzzleHttp\Exception\GuzzleException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Plusval\Client;
use Kanvas\Connectors\Plusval\Helpers\PhoneHelper;
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
    * @param string $customerName Customer name (e.g., "Juan Perez")
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
        $agentPhone = PhoneHelper::formatPhoneNumber($agentPhone);

        return $this->client->getDeals($agentPhone, $customerName);
    }
}
