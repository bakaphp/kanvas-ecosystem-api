<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DriveCentric\Services;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\DriveCentric\Client;
use Kanvas\Connectors\DriveCentric\Enums\ConfigurationEnum;
use Kanvas\Connectors\DriveCentric\Enums\CustomFieldEnums;
use Kanvas\Connectors\DriveCentric\Exceptions\DriveCentricException;
use Kanvas\Guild\Leads\Models\Lead;

class LeadService
{
    public Client $client;

    public function __construct(
        protected Apps $app,
        protected Companies $company
    ) {
        $this->client = new Client($this->app, $this->company);
    }

    /**
     * Create a new lead.
     * POST /api/stores/{storeId}/leads
     */
    public function createLead(array $leadData): array
    {
        $response = $this->client->post('/api/stores/{+storeId}/leads', $leadData);

        return $response->json() ?? [];
    }

    /**
     * Upsert a deal (create or update).
     * POST /api/stores/{storeId}/deal/upsert
     */
    public function upsertDeal(array $dealData): array
    {
        $response = $this->client->post('/api/stores/{+storeId}/deal/upsert', [
            'deal' => $dealData,
        ]);

        return $response->json('deal') ?? [];
    }

    /**
     * Get deal by ID.
     * GET /api/stores/{storeId}/deal/{dealId}
     */
    public function getDealById(string $dealId): array
    {
        $response = $this->client->get("/api/stores/{+storeId}/deal/{$dealId}");
        $deal = $response->json();

        if (empty($deal)) {
            throw new DriveCentricException("Deal with ID {$dealId} not found");
        }

        return $deal;
    }

    /**
     * Get deals by date range.
     * GET /api/stores/{storeId}/deal/byrange
     *
     * @param string $startDate Start date in YYYY-MM-DD format
     * @param string $endDate End date in YYYY-MM-DD format
     * @param int $offset Number of rows to skip (must be >= 0)
     */
    public function getDealsByRange(
        string $startDate,
        string $endDate,
        int $offset = 0
    ): array {
        $params = [
            'start' => $startDate,
            'end' => $endDate,
            'offset' => $offset,
        ];

        $response = $this->client->get('/api/stores/{+storeId}/deal/byrange', $params);

        return $response->json('deals') ?? [];
    }

    /**
     * Create deal from lead data (legacy method for backward compatibility).
     */
    public function create(array $lead): array
    {
        return $this->upsertDeal($lead);
    }

    /**
     * Convert Kanvas Lead to DriveCentric deal format.
     */
    public function formatLeadForDriveCentric(Lead $lead): array
    {
        $people = $lead->people;

        // Use getAllPhones to get both cellphones and regular phones
        $phones = $people->getAllPhones()->map(function ($phone) {
            // Map phone types - cellphones should be Mobile, regular phones as Home
            $phoneType = $phone->type->name === 'Cellphone' ? 'Mobile' : 'Home';

            return [
                'type' => $phoneType,
                'value' => $phone->value,
            ];
        })->toArray();

        // Use getEmails method
        $emails = $people->getEmails()->map(function ($email) {
            return [
                'type' => 'Home',
                'value' => $email->value,
            ];
        })->toArray();

        $sourceType = $this->app->get(ConfigurationEnum::DEFAULT_SOURCE_TYPE->value) ?? 'Internet';
        $sourceDescription = $lead->source->name
            ?? $this->app->get(ConfigurationEnum::DEFAULT_SOURCE_DESCRIPTION->value)
            ?? $lead->company->name;

        $customer = [
            'isPrimaryBuyer' => true,
            'type' => 'Individual',
            'firstName' => $people->firstname,
            'middleName' => $people->middlename ?? '',
            'lastName' => $people->lastname,
            'identifiers' => [
                [
                    'type' => 'PartnerId',
                    'value' => (string) $people->getId(), // Use people ID for customer identifier
                ],
            ],
            'phones' => $phones,
            'emails' => $emails,
        ];

        // Add customer CrmId identifier if it exists
        $customerId = $people->get(CustomFieldEnums::DRIVE_CENTRIC_CUSTOMER_ID->value);
        if ($customerId) {
            $customer['identifiers'][] = ['type' => 'CrmId', 'value' => $customerId];
        }

        // Add birthdate only if valid and format as YYYY-MM-DD
        if ($people->dob && $people->dob->year > 1900) {
            $customer['birthdate'] = $people->dob->format('Y-m-d');
        }

        $dealData = [
            'source' => [
                'type' => $sourceType,
                'description' => $sourceDescription,
            ],
            'customers' => [$customer],
            'identifiers' => [
                [
                    'type' => 'PartnerId',
                    'value' => (string) $lead->getId(), // Use lead ID for deal identifier
                ],
            ],
            'stage' => $this->mapLeadStatusToStage($lead),
        ];

        // Add deal CrmId identifier if it exists
        $dealId = $lead->get(CustomFieldEnums::DRIVE_CENTRIC_DEAL_ID->value);
        if ($dealId) {
            $dealData['identifiers'][] = ['type' => 'CrmId', 'value' => $dealId];
        }

        // Add salesperson1 if lead owner has DriveCentric user ID
        $salesperson = $this->formatSalesperson($lead);
        if ($salesperson) {
            $dealData['salesperson1'] = $salesperson;
        }

        return $dealData;
    }

    /**
     * Format salesperson data from lead owner.
     */
    protected function formatSalesperson(Lead $lead): ?array
    {
        $owner = $lead->owner;

        if (! $owner) {
            return null;
        }

        $driveCentricUserId = $owner->get(ConfigurationEnum::getUserKey($lead->company));

        if (! $driveCentricUserId) {
            return null;
        }

        return [
            'identifiers' => [
                [
                    'type' => 'CrmId',
                    'value' => $driveCentricUserId,
                ],
            ],
            'firstName' => $owner->firstname,
            'lastName' => $owner->lastname,
        ];
    }

    /**
     * Map Kanvas lead status to DriveCentric pipeline stage.
     */
    protected function mapLeadStatusToStage(Lead $lead): string
    {
        $status = $lead->status()?->first()?->name ?? '';

        return match (strtolower($status)) {
            'new' => 'Undefined',
            'contacted' => 'Contacted',
            'qualified' => 'Qualified',
            'proposal' => 'Proposal',
            'negotiation' => 'Negotiation',
            'sold', 'closed-won' => 'Sold',
            'lost', 'closed-lost' => 'Dead',
            default => 'Undefined',
        };
    }

    /**
     * Update customer credit application.
     * POST /api/stores/{storeId}/customers/{customerId}/creditApp
     * Note: API returns empty body on success (HTTP 200)
     */
    public function updateCustomerCreditApp(string $customerId, array $creditAppData): array
    {
        $this->client->post(
            "/api/stores/{+storeId}/customers/{$customerId}/creditApp",
            $creditAppData
        );

        // API returns empty body on success, so we return the submitted data as confirmation
        return [
            'success' => true,
            'customerId' => $customerId,
            'creditApp' => $creditAppData,
        ];
    }
}
