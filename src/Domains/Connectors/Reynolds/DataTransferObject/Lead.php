<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Reynolds\DataTransferObject;

use Kanvas\Connectors\Reynolds\Enums\CustomFieldEnum;
use Kanvas\Guild\Leads\Models\Lead as LeadModel;
use Spatie\LaravelData\Data;

class Lead extends Data
{
    public function __construct(
        public readonly ?string $prospectId,
        public readonly string $prospectCategory,
        public readonly string $prospectType,
        public readonly ?string $prospectStatus,
        public readonly ?string $providerName,
        public readonly ?string $prospectNote,
        public readonly ?string $isAiGenerated,
        public readonly ?string $primarySalesPerson,
        public readonly array $desiredVehicle,
        public readonly array $potentialTrade,
        public readonly Customer $customer,
    ) {
    }

    public static function fromLead(LeadModel $lead): self
    {
        $prospectId = $lead->get(CustomFieldEnum::PROSPECT_ID->value);
        $prospectType = $lead->get(CustomFieldEnum::PROSPECT_TYPE->value)
            ?? ($lead->type?->name ?? 'Internet');

        $desiredVehicle = $lead->get(CustomFieldEnum::VEHICLE_OF_INTEREST->value);
        $tradeIn = $lead->get(CustomFieldEnum::TRADE_IN->value);

        return new self(
            prospectId: $prospectId !== null ? (string) $prospectId : null,
            prospectCategory: 'Sales',
            prospectType: (string) $prospectType,
            prospectStatus: $lead->status?->name,
            providerName: $lead->source?->name,
            prospectNote: $lead->description,
            isAiGenerated: null,
            primarySalesPerson: self::ownerName($lead),
            desiredVehicle: is_array($desiredVehicle) ? $desiredVehicle : [],
            potentialTrade: is_array($tradeIn) ? $tradeIn : [],
            customer: Customer::fromPeople($lead->people),
        );
    }

    /**
     * Build the Prospect XML section.
     */
    public function toProspect(): array
    {
        return array_filter([
            'ProspectCategory' => $this->prospectCategory,
            'ProviderName' => $this->providerName,
            'IsAiGenerated' => $this->isAiGenerated,
            'ProspectType' => $this->prospectType,
            'ProspectStatus' => $this->prospectStatus,
            'ProspectNote' => $this->prospectNote,
            'PrimarySalesPerson' => $this->primarySalesPerson,
        ], fn ($v) => $v !== null && $v !== '');
    }

    public function toDesiredVehicle(): array
    {
        if (empty($this->desiredVehicle)) {
            return [];
        }

        return array_filter([
            'StockType' => $this->desiredVehicle['stock_type'] ?? null,
            'Vin' => $this->desiredVehicle['vin'] ?? null,
            'VehicleYear' => $this->desiredVehicle['year'] ?? null,
            'VehicleMake' => $this->desiredVehicle['make'] ?? null,
            'VehicleModel' => $this->desiredVehicle['model'] ?? null,
            'VehicleStyle' => $this->desiredVehicle['style'] ?? null,
            'StockId' => $this->desiredVehicle['stock_id'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    public function toPotentialTrade(): array
    {
        if (empty($this->potentialTrade)) {
            return [];
        }

        return array_filter([
            'TradeVehicleVin' => $this->potentialTrade['vin'] ?? null,
            'TradeVehicleYear' => $this->potentialTrade['year'] ?? null,
            'TradeVehicleMake' => $this->potentialTrade['make'] ?? null,
            'TradeVehicleModel' => $this->potentialTrade['model'] ?? null,
            'TradeVehicleOdometer' => $this->potentialTrade['odometer'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    private static function ownerName(LeadModel $lead): ?string
    {
        $owner = $lead->owner;
        if ($owner === null) {
            return null;
        }

        $name = trim(($owner->firstname ?? '') . ' ' . ($owner->lastname ?? ''));

        return $name !== '' ? $name : null;
    }
}
