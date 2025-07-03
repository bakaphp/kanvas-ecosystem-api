<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Plusval\Services;

use GuzzleHttp\Exception\GuzzleException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Plusval\Client;
use Kanvas\Connectors\Plusval\Helpers\PhoneHelper;
use Kanvas\Exceptions\ValidationException;

class PropertiesService
{
    protected Client $client;

    public function __construct(
        protected Apps $app,
        protected Companies $company
    ) {
        $this->client = new Client($app, $company);
    }

    /**
     * Get properties by agent phone number and search criteria
     *
     * @param string $agentPhone Agent's phone number (e.g., "+1 809-864-6241")
     * @param string $criteria Search criteria (e.g., "Torre Grande")
     * @throws GuzzleException
     * @throws ValidationException
     */
    public function getPropertiesByAgentAndCriteria(string $agentPhone, string $criteria): array
    {
        if (empty($agentPhone)) {
            throw new ValidationException('Agent phone number is required');
        }

        if (empty($criteria)) {
            throw new ValidationException('Search criteria is required');
        }

        // Clean and format phone number if needed
        $agentPhone = PhoneHelper::formatPhoneNumber($agentPhone);

        return $this->client->getProperties($agentPhone, $criteria);
    }

    /**
     * Send properties to a deal
     *
     * @param string $agentPhone Agent's phone number (e.g., "+1 809-864-6241")
     * @param int $dealId Deal ID to which properties will be sent
     * @param array $propertiesIds Array of property IDs to send
     * @throws GuzzleException
     * @throws ValidationException
     */
    public function sendPropertiesToDeal(string $agentPhone, int $dealId, array $propertiesIds): array
    {
        if (empty($agentPhone)) {
            throw new ValidationException('Agent phone number is required');
        }

        if (empty($dealId)) {
            throw new ValidationException('Deal ID is required');
        }

        if (empty($propertiesIds) || ! is_array($propertiesIds)) {
            throw new ValidationException('Properties IDs must be a non-empty array');
        }

        // Clean and format phone number if needed
        $agentPhone = PhoneHelper::formatPhoneNumber($agentPhone);

        return $this->client->sendProperties($agentPhone, $dealId, $propertiesIds);
    }
}
