<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DriveCentric\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\DriveCentric\Enums\ConfigurationEnum;
use Kanvas\Connectors\DriveCentric\Enums\CustomFieldEnums;
use Kanvas\Guild\Leads\DataTransferObject\Lead as DataTransferObjectLead;
use Kanvas\Guild\Leads\Models\LeadSource;
use Kanvas\Guild\Leads\Models\LeadStatus;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Guild\LeadSources\Actions\CreateLeadSourceAction;
use Kanvas\Guild\LeadSources\DataTransferObject\LeadSource as DataTransferObjectLeadSource;
use Kanvas\Users\Models\UserConfig;
use Throwable;

class Lead extends DataTransferObjectLead
{
    public static function fromDriveCentric(
        array $data,
        AppInterface $app,
        Companies $company,
        UserInterface $user
    ): self {
        // Get primary customer from deal
        $primaryCustomer = self::getPrimaryCustomer($data['customers'] ?? []);

        // Create people DTO from customer data
        $people = People::fromDriveCentric(
            $app,
            $company,
            $user,
            $primaryCustomer
        );

        // Get or create lead source
        $leadSource = self::getLeadSource($data, $app, $company);

        // Get lead type
        $leadType = self::getLeadType($data, $app, $company);

        // Get lead status based on pipeline stage
        $leadStatus = self::getLeadStatus($data);

        // Determine lead owner
        $leadOwnerId = self::getLeadOwnerId($data, $company, $user);

        $dealId = self::getDealIdentifier($data, 'CrmId');
        $partnerId = self::getDealIdentifier($data, 'PartnerId');

        return self::from([
            'app' => $app,
            'branch' => $company->defaultBranch,
            'user' => $user,
            'title' => ($people->firstname ?? '') . ' ' . ($people->lastname ?? '') . ' Opportunity',
            'pipeline_stage_id' => 0,
            'people' => $people,
            'type_id' => $leadType?->id ?? 0,
            'source_id' => $leadSource?->id ?? 0,
            'status_id' => $leadStatus?->id ?? 0,
            'leads_owner_id' => $leadOwnerId,
            'custom_fields' => [
                CustomFieldEnums::DRIVE_CENTRIC_DEAL_ID->value => $dealId,
            ],
        ]);
    }

    /**
     * Get the primary customer from the customers array.
     */
    protected static function getPrimaryCustomer(array $customers): array
    {
        foreach ($customers as $customer) {
            if (($customer['isPrimaryBuyer'] ?? false) === true) {
                return $customer;
            }
        }

        return $customers[0] ?? [];
    }

    /**
     * Get or create lead source from deal data.
     */
    protected static function getLeadSource(array $data, AppInterface $app, Companies $company): ?LeadSource
    {
        $sourceData = $data['source'] ?? [];
        $sourceName = $sourceData['description'] ?? $sourceData['type'] ?? null;

        if (! $sourceName) {
            return null;
        }

        try {
            return (new CreateLeadSourceAction(
                new DataTransferObjectLeadSource(
                    app: $app,
                    company: $company,
                    leads_types_id: null,
                    name: $sourceName,
                    is_active: true,
                    description: $sourceData['type'] ?? ''
                )
            ))->execute();
        } catch (Throwable $e) {
            report($e);

            return LeadSource::where('name', $sourceName)
                ->fromApp($app)
                ->fromCompany($company)
                ->first();
        }
    }

    /**
     * Get lead type from deal data.
     */
    protected static function getLeadType(array $data, AppInterface $app, Companies $company): ?LeadType
    {
        $sourceType = $data['source']['type'] ?? 'Internet';
        $typeName = strtoupper($sourceType);

        return LeadType::where('name', $typeName)
            ->fromApp($app)
            ->fromCompany($company)
            ->first();
    }

    /**
     * Get lead status from pipeline stage.
     */
    protected static function getLeadStatus(array $data): ?LeadStatus
    {
        $stage = $data['pipelineStage'] ?? $data['stage'] ?? 'Undefined';

        return LeadStatus::firstOrCreate([
            'name' => $stage,
        ]);
    }

    /**
     * Get lead owner ID from deal data.
     */
    protected static function getLeadOwnerId(array $data, Companies $company, UserInterface $user): int
    {
        // Try to find user from deal's salesperson data
        $salesUser = $data['salesperson1'] ?? $data['salesPerson'] ?? $data['user'] ?? null;

        if ($salesUser) {
            // Extract CrmId from identifiers
            $salesUserId = null;
            if (isset($salesUser['identifiers'])) {
                foreach ($salesUser['identifiers'] as $identifier) {
                    if (($identifier['type'] ?? '') === 'CrmId') {
                        $salesUserId = $identifier['value'] ?? null;

                        break;
                    }
                }
            } elseif (isset($salesUser['userId'])) {
                $salesUserId = $salesUser['userId'];
            }

            if ($salesUserId) {
                $userConfig = UserConfig::where('name', 'LIKE', ConfigurationEnum::getUserKey($company) . '%')
                    ->where('value', $salesUserId)
                    ->orderBy('users_id', 'DESC')
                    ->first();

                if ($userConfig) {
                    return $userConfig->users_id;
                }
            }
        }

        return 0;
    }

    /**
     * Get identifier value from deal identifiers.
     */
    protected static function getDealIdentifier(array $data, string $type): ?string
    {
        $identifiers = $data['identifiers'] ?? [];

        foreach ($identifiers as $identifier) {
            if (($identifier['type'] ?? '') === $type) {
                return $identifier['value'] ?? null;
            }
        }

        // Also check deal ID directly
        if ($type === 'CrmId' && isset($data['dealId'])) {
            return (string) $data['dealId'];
        }

        return null;
    }

    /**
     * Extract vehicle of interest from deal data.
     */
    protected static function extractVehicleOfInterest(array $data): ?array
    {
        $vehicles = $data['vehicleInterests'] ?? $data['vehiclesOfInterest'] ?? $data['vehicles'] ?? [];

        if (empty($vehicles)) {
            return null;
        }

        $vehicleData = $vehicles[0] ?? [];

        // Handle nested vehicle structure
        $vehicle = $vehicleData['vehicle'] ?? $vehicleData;

        return [
            'year' => $vehicle['year'] ?? null,
            'make' => $vehicle['make'] ?? null,
            'model' => $vehicle['model'] ?? null,
            'trim' => $vehicle['trim'] ?? null,
            'vin' => $vehicle['vin'] ?? null,
            'stockNumber' => $vehicle['stockNumber'] ?? null,
            'price' => $vehicle['price'] ?? null,
            'mileage' => $vehicle['mileage'] ?? null,
            'exteriorColor' => $vehicle['exteriorColor'] ?? null,
            'interiorColor' => $vehicle['interiorColor'] ?? null,
            'isNew' => ($vehicle['stockType'] ?? $vehicleData['stockType'] ?? '') === 'New',
        ];
    }

    /**
     * Extract trade-in from deal data.
     */
    protected static function extractTradeIn(array $data): ?array
    {
        $tradeIns = $data['tradeIns'] ?? [];

        if (empty($tradeIns)) {
            return null;
        }

        $tradeIn = $tradeIns[0] ?? [];

        return [
            'year' => $tradeIn['year'] ?? null,
            'make' => $tradeIn['make'] ?? null,
            'model' => $tradeIn['model'] ?? null,
            'vin' => $tradeIn['vin'] ?? null,
            'mileage' => $tradeIn['mileage'] ?? null,
            'value' => $tradeIn['value'] ?? null,
            'payoff' => $tradeIn['payoff'] ?? null,
        ];
    }
}
