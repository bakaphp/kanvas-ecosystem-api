<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DriveCentric\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Connectors\DriveCentric\Enums\CustomFieldEnums;
use Kanvas\Connectors\DriveCentric\Services\LeadService;
use Kanvas\Guild\Leads\Models\Lead as LeadModel;

class PushLeadAction
{
    protected LeadService $leadService;

    public function __construct(
        protected LeadModel $lead
    ) {
        $this->leadService = new LeadService($this->lead->app, $this->lead->company);
    }

    public function execute(): array
    {
        return DB::transaction(function () {
            $this->lead->lockForUpdate();

            // Format lead data for DriveCentric (includes customer/people data)
            $dealData = $this->leadService->formatLeadForDriveCentric($this->lead);

            // Add vehicle of interest if exists
            $vehicleOfInterest = $this->lead->get(CustomFieldEnums::VEHICLE_OF_INTEREST->value);
            if (! empty($vehicleOfInterest)) {
                $dealData['vehicleInterests'] = [$this->formatVehicleOfInterest($vehicleOfInterest)];
            }

            // Add trade-in if exists
            $tradeIn = $this->lead->get(CustomFieldEnums::TRADE_IN->value);
            if (! empty($tradeIn)) {
                $dealData['tradeIns'] = [$this->formatTradeIn($tradeIn)];
            }

            // Upsert deal in DriveCentric - this creates/updates the deal AND the customer
            $response = $this->leadService->upsertDeal($dealData);

            // Extract and save identifiers (deal ID and customer ID)
            $this->saveIdentifiers($response);

            // Handle co-buyer if exists (add to the deal, not as separate customer)
            $this->handleCoBuyer();

            return $response;
        });
    }

    protected function saveIdentifiers(array $response): void
    {
        // Save deal identifier on the Lead
        $dealIdentifier = $this->extractIdentifier($response['identifiers'] ?? [], 'CrmId');
        if ($dealIdentifier) {
            $this->lead->set(CustomFieldEnums::DRIVE_CENTRIC_DEAL_ID->value, $dealIdentifier);
        }

        // Save customer identifier on the People record
        // The customer is created automatically when the deal is created
        $customers = $response['customers'] ?? [];
        if (! empty($customers[0]['identifiers'])) {
            $customerIdentifier = $this->extractIdentifier($customers[0]['identifiers'], 'CrmId');
            if ($customerIdentifier) {
                $this->lead->people->set(CustomFieldEnums::DRIVE_CENTRIC_CUSTOMER_ID->value, $customerIdentifier);
            }
        }
    }

    protected function extractIdentifier(array $identifiers, string $type): ?string
    {
        foreach ($identifiers as $identifier) {
            if (($identifier['type'] ?? '') === $type) {
                return $identifier['value'] ?? null;
            }
        }

        return null;
    }

    protected function handleCoBuyer(): void
    {
        $coBuyerParticipant = $this->lead->participants()
            ->whereHas('type', function ($query) {
                $query->where('name', 'Co-buyer');
            })
            ->latest()
            ->first();

        if (! $coBuyerParticipant) {
            return;
        }

        $coBuyerPeople = $coBuyerParticipant->people;

        if (! $coBuyerPeople || $coBuyerPeople->id === $this->lead->people->id) {
            return;
        }

        $dealId = $this->lead->get(CustomFieldEnums::DRIVE_CENTRIC_DEAL_ID->value);

        if (! $dealId) {
            return;
        }

        // Check if co-buyer already has a customer ID
        $coBuyerCustomerId = $coBuyerPeople->get(CustomFieldEnums::DRIVE_CENTRIC_CUSTOMER_ID->value);

        // Build co-buyer customer data
        $coBuyerData = [
            'isPrimaryBuyer' => false,
            'type' => 'Individual',
            'firstName' => $coBuyerPeople->firstname,
            'lastName' => $coBuyerPeople->lastname,
        ];

        // Add identifiers
        $identifiers = [
            ['type' => 'PartnerId', 'value' => (string) $coBuyerPeople->getId()],
        ];

        if ($coBuyerCustomerId) {
            $identifiers[] = ['type' => 'CrmId', 'value' => $coBuyerCustomerId];
        }

        $coBuyerData['identifiers'] = $identifiers;

        // Add contact info
        $emails = $coBuyerPeople->getEmails()->map(fn ($email) => [
            'type' => 'Home',
            'value' => $email->value,
        ])->toArray();

        $phones = $coBuyerPeople->getAllPhones()->map(function ($phone) {
            $phoneType = $phone->type->name === 'Cellphone' ? 'Mobile' : 'Home';

            return [
                'type' => $phoneType,
                'value' => $phone->value,
            ];
        })->toArray();

        if (! empty($emails)) {
            $coBuyerData['emails'] = $emails;
        }

        if (! empty($phones)) {
            $coBuyerData['phones'] = $phones;
        }

        // Get full deal data with all required fields (source, primary buyer, etc.)
        $dealData = $this->leadService->formatLeadForDriveCentric($this->lead);

        // Add co-buyer to customers array (primary buyer is already at index 0)
        $dealData['customers'][] = $coBuyerData;

        // Upsert deal with both primary buyer and co-buyer
        $response = $this->leadService->upsertDeal($dealData);

        // Save co-buyer's customer ID if returned
        $customers = $response['customers'] ?? [];
        foreach ($customers as $customer) {
            $isPrimary = $customer['isPrimaryBuyer'] ?? true;
            if (! $isPrimary) {
                $coBuyerCrmId = $this->extractIdentifier($customer['identifiers'] ?? [], 'CrmId');
                if ($coBuyerCrmId) {
                    $coBuyerPeople->set(CustomFieldEnums::DRIVE_CENTRIC_CUSTOMER_ID->value, $coBuyerCrmId);
                }

                break;
            }
        }
    }

    public function addVehicleOfInterest(array $vehicleData): array
    {
        $dealId = $this->lead->get(CustomFieldEnums::DRIVE_CENTRIC_DEAL_ID->value);

        if (! $dealId) {
            $this->execute();
            $dealId = $this->lead->get(CustomFieldEnums::DRIVE_CENTRIC_DEAL_ID->value);
        }

        // Get full deal data and append vehicle of interest
        $dealData = $this->leadService->formatLeadForDriveCentric($this->lead);
        //$dealData['identifiers'][] = ['type' => 'CrmId', 'value' => $dealId];
        $dealData['vehicleInterests'] = [$this->formatVehicleOfInterest($vehicleData)];

        return $this->leadService->upsertDeal($dealData);
    }

    public function addTradeIn(array $tradeInData): array
    {
        $dealId = $this->lead->get(CustomFieldEnums::DRIVE_CENTRIC_DEAL_ID->value);

        if (! $dealId) {
            $this->execute();
            $dealId = $this->lead->get(CustomFieldEnums::DRIVE_CENTRIC_DEAL_ID->value);
        }

        // Build vehicle identifiers
        $identifiers = [
            [
                'type' => 'PartnerId',
                'value' => (string) $this->lead->getId() . '-trade-' . time(),
            ],
        ];

        // Add CrmId if it exists to avoid duplication
        if (isset($tradeInData['_vehicle_crm_id'])) {
            $identifiers[] = [
                'type' => 'CrmId',
                'value' => $tradeInData['_vehicle_crm_id'],
            ];
        }

        // Build vehicle object per API spec
        $vehicle = [
            'identifiers' => $identifiers,
            'year' => (int) ($tradeInData['year'] ?? 0),
            'make' => $tradeInData['make'] ?? '',
            'model' => $tradeInData['model'] ?? '',
        ];

        // Add optional vehicle fields
        if (isset($tradeInData['vin'])) {
            $vehicle['vin'] = $tradeInData['vin'];
        }
        if (isset($tradeInData['mileage'])) {
            $vehicle['mileage'] = (int) $tradeInData['mileage'];
        }
        if (isset($tradeInData['trim'])) {
            $vehicle['trim'] = $tradeInData['trim'];
        }
        if (isset($tradeInData['stockNumber'])) {
            $vehicle['stockNumber'] = $tradeInData['stockNumber'];
        }
        if (isset($tradeInData['exteriorColor'])) {
            $vehicle['exteriorColor'] = $tradeInData['exteriorColor'];
        }
        if (isset($tradeInData['interiorColor'])) {
            $vehicle['interiorColor'] = $tradeInData['interiorColor'];
        }

        // Build trade-in object per API spec
        $tradeIn = [
            'vehicle' => $vehicle,
        ];

        // Add optional trade-in fields
        if (isset($tradeInData['payoffAmount'])) {
            $tradeIn['payoffAmount'] = (float) $tradeInData['payoffAmount'];
        }
        if (isset($tradeInData['payoff'])) {
            $tradeIn['payoffAmount'] = (float) $tradeInData['payoff'];
        }
        if (isset($tradeInData['allowance'])) {
            $tradeIn['allowance'] = (float) $tradeInData['allowance'];
        }
        if (isset($tradeInData['actualCashValue'])) {
            $tradeIn['actualCashValue'] = (float) $tradeInData['actualCashValue'];
        }
        if (isset($tradeInData['value'])) {
            $tradeIn['actualCashValue'] = (float) $tradeInData['value'];
        }

        // Get full deal data and append trade-in
        $dealData = $this->leadService->formatLeadForDriveCentric($this->lead);
        //$dealData['identifiers'][] = ['type' => 'CrmId', 'value' => $dealId];
        $dealData['tradeIns'] = [$tradeIn];

        return $this->leadService->upsertDeal($dealData);
    }

    protected function formatVehicleOfInterest(array $vehicleData): array
    {
        $identifiers = [
            [
                'type' => 'PartnerId',
                'value' => (string) $this->lead->getId() . '-voi',
            ],
        ];

        // Add CrmId if it exists to avoid duplication
        if (isset($vehicleData['_vehicle_crm_id'])) {
            $identifiers[] = [
                'type' => 'CrmId',
                'value' => $vehicleData['_vehicle_crm_id'],
            ];
        }

        $vehicle = [
            'identifiers' => $identifiers,
            'year' => (int) ($vehicleData['yearFrom'] ?? $vehicleData['year'] ?? 0),
            'make' => $vehicleData['make'] ?? '',
            'model' => $vehicleData['model'] ?? '',
        ];

        // Add optional vehicle fields
        if (isset($vehicleData['trim'])) {
            $vehicle['trim'] = $vehicleData['trim'];
        }
        if (isset($vehicleData['vin'])) {
            $vehicle['vin'] = $vehicleData['vin'];
        }
        if (isset($vehicleData['stock_number']) || isset($vehicleData['stockNumber'])) {
            $vehicle['stockNumber'] = $vehicleData['stock_number'] ?? $vehicleData['stockNumber'];
        }
        if (isset($vehicleData['mileage'])) {
            $vehicle['mileage'] = (int) $vehicleData['mileage'];
        }
        if (isset($vehicleData['color']) || isset($vehicleData['exteriorColor'])) {
            $vehicle['exteriorColor'] = $vehicleData['color'] ?? $vehicleData['exteriorColor'];
        }
        if (isset($vehicleData['interiorColor'])) {
            $vehicle['interiorColor'] = $vehicleData['interiorColor'];
        }

        // Determine stock type from isNew field
        $stockType = 'Used';
        if (isset($vehicleData['isNew'])) {
            $stockType = $vehicleData['isNew'] ? 'New' : 'Used';
        } elseif (isset($vehicleData['stockType'])) {
            $stockType = $vehicleData['stockType'];
        }

        return [
            'priority' => (int) ($vehicleData['priority'] ?? 1),
            'stockType' => $stockType,
            'vehicle' => $vehicle,
        ];
    }

    protected function formatTradeIn(array $tradeInData): array
    {
        // Handle nested vehicle structure from AddTradeInToDealAction
        $vehicleData = $tradeInData['vehicle'] ?? $tradeInData;

        $identifiers = [
            [
                'type' => 'PartnerId',
                'value' => (string) $this->lead->getId() . '-trade-' . time(),
            ],
        ];

        // Add CrmId if it exists to avoid duplication
        if (isset($tradeInData['_vehicle_crm_id'])) {
            $identifiers[] = [
                'type' => 'CrmId',
                'value' => $tradeInData['_vehicle_crm_id'],
            ];
        } elseif (isset($vehicleData['_vehicle_crm_id'])) {
            $identifiers[] = [
                'type' => 'CrmId',
                'value' => $vehicleData['_vehicle_crm_id'],
            ];
        }

        $vehicle = [
            'identifiers' => $identifiers,
            'year' => (int) ($vehicleData['year'] ?? 0),
            'make' => $vehicleData['make'] ?? '',
            'model' => $vehicleData['model'] ?? '',
        ];

        // Add optional vehicle fields
        if (isset($vehicleData['vin'])) {
            $vehicle['vin'] = $vehicleData['vin'];
        }
        if (isset($vehicleData['mileage'])) {
            $vehicle['mileage'] = (int) $vehicleData['mileage'];
        }
        if (isset($vehicleData['trim'])) {
            $vehicle['trim'] = $vehicleData['trim'];
        }
        if (isset($vehicleData['stockNumber'])) {
            $vehicle['stockNumber'] = $vehicleData['stockNumber'];
        }
        if (isset($vehicleData['exteriorColor'])) {
            $vehicle['exteriorColor'] = $vehicleData['exteriorColor'];
        }
        if (isset($vehicleData['interiorColor'])) {
            $vehicle['interiorColor'] = $vehicleData['interiorColor'];
        }

        $tradeIn = [
            'vehicle' => $vehicle,
        ];

        // Add trade-in specific fields
        if (isset($tradeInData['payoffAmount'])) {
            $tradeIn['payoffAmount'] = (float) $tradeInData['payoffAmount'];
        } elseif (isset($tradeInData['payoff'])) {
            $tradeIn['payoffAmount'] = (float) $tradeInData['payoff'];
        }

        if (isset($tradeInData['allowance'])) {
            $tradeIn['allowance'] = (float) $tradeInData['allowance'];
        }

        if (isset($tradeInData['actualCashValue'])) {
            $tradeIn['actualCashValue'] = (float) $tradeInData['actualCashValue'];
        } elseif (isset($tradeInData['value'])) {
            $tradeIn['actualCashValue'] = (float) $tradeInData['value'];
        }

        // Add lienholder if exists
        if (isset($tradeInData['lienholder']) && ! empty($tradeInData['lienholder'])) {
            $tradeIn['lienholder'] = $tradeInData['lienholder'];
        }

        return $tradeIn;
    }
}
