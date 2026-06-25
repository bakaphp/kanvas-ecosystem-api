<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Reynolds\Entities;

class Lead
{
    public function __construct(
        public ?string $prospectId = null,
        public ?string $prospectType = null,
        public ?string $prospectStatus = null,
        public ?string $prospectStatusType = null,
        public ?string $prospectNote = null,
        public ?string $providerName = null,
        public ?string $providerService = null,
        public ?string $isCiLead = null,
        public ?string $isAiGenerated = null,
        public ?string $primarySalesPerson = null,
        public ?string $secondarySalesPerson = null,
        public ?string $manager = null,
        public ?string $bdcUser = null,
        public array $desiredVehicle = [],
        public array $potentialTrade = [],
        public ?Customer $customer = null,
    ) {
    }

    /**
     * Build from the parsed `rey_SalesAssistCRMPublishLeadUpdate.Record` element.
     */
    public static function fromRecord(array $record): self
    {
        $prospect = $record['Prospect'] ?? [];
        $identifier = $record['Identifier'] ?? [];
        $desired = $record['DesiredVehicle'] ?? [];
        $trade = $record['PotentialTrade'] ?? [];

        // The LDU spec example puts ProspectId under <Identifier>, but real OSL
        // envelopes from R&R inline it under <Prospect>. Fall back so both
        // shapes resolve to the same field.
        $prospectId = $identifier['ProspectId'] ?? $prospect['ProspectId'] ?? null;

        return new self(
            prospectId: $prospectId,
            prospectType: $prospect['ProspectType'] ?? null,
            prospectStatus: $prospect['ProspectStatus'] ?? null,
            prospectStatusType: $prospect['ProspectStatusType'] ?? null,
            prospectNote: $prospect['ProspectNote'] ?? null,
            providerName: $prospect['ProviderName'] ?? null,
            providerService: $prospect['ProviderService'] ?? null,
            isCiLead: $prospect['IsCiLead'] ?? null,
            isAiGenerated: $prospect['IsAiGenerated'] ?? null,
            primarySalesPerson: $prospect['PrimarySalesPerson'] ?? null,
            secondarySalesPerson: $prospect['SecondarySalesPerson'] ?? null,
            manager: $prospect['Manager'] ?? null,
            bdcUser: $prospect['BDCUser'] ?? null,
            desiredVehicle: self::normalizeDesiredVehicle($desired),
            potentialTrade: self::normalizeTrade($trade),
            customer: Customer::fromRecord($record),
        );
    }

    private static function normalizeDesiredVehicle(array $node): array
    {
        if (empty($node)) {
            return [];
        }

        return array_filter([
            'stock_type' => $node['StockType'] ?? null,
            'vin' => $node['Vin'] ?? null,
            'year' => $node['VehicleYear'] ?? null,
            'make' => $node['VehicleMake'] ?? null,
            'model' => $node['VehicleModel'] ?? null,
            'style' => $node['VehicleStyle'] ?? null,
            'stock_id' => $node['StockId'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    private static function normalizeTrade(array $node): array
    {
        if (empty($node)) {
            return [];
        }

        return array_filter([
            'vin' => $node['TradeVehicleVin'] ?? null,
            'year' => $node['TradeVehicleYear'] ?? null,
            'make' => $node['TradeVehicleMake'] ?? null,
            'model' => $node['TradeVehicleModel'] ?? null,
            'odometer' => $node['TradeVehicleOdometer'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');
    }
}
