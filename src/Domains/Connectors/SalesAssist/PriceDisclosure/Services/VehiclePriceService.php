<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\PriceDisclosure\Services;

use Baka\Support\Str;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Connectors\SalesAssist\Enums\LeadCustomFieldEnum;
use Kanvas\Connectors\SalesAssist\PriceDisclosure\DataTransferObject\VehiclePrice;
use Kanvas\Connectors\SalesAssist\PriceDisclosure\Enums\PriceDisclosureConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Inventory\Variants\Models\Variants;

class VehiclePriceService
{
    private const string SOURCE_SYSTEM = 'variant_channel';

    private const string DEALER_FEE_TYPE = 'dealer_fee';

    public function __construct(
        private readonly Lead $lead,
    ) {
    }

    /**
     * The VIN the conversation is about: the one named explicitly, else the lead's vehicle of
     * interest. Null means no specific unit is in scope, which is not the same as an unknown one.
     */
    public function vehicleKey(?string $vin): ?string
    {
        return Str::trimToNull($vin) ?? $this->leadVehicleVin();
    }

    /**
     * An explicit VIN from the conversation never falls back to the lead's vehicle of interest: a
     * miss on the unit the customer named must suppress, not quote another vehicle.
     */
    public function resolveVariant(string $vin): ?Variants
    {
        $variant = Variants::fromApp($this->lead->app)
            ->fromCompany($this->lead->company)
            ->notDeleted()
            ->where('sku', $vin)
            ->first();

        return $variant instanceof Variants ? $variant : null;
    }

    /**
     * The stored channel price is the actual price before government-required charges; the
     * dealer-required fee comes from the company setting and is the only addition to the total.
     * Swapping in the dealer pricing feed later only touches this method.
     */
    public function priceFor(Variants $variant): ?VehiclePrice
    {
        try {
            $channelInfo = $variant->getChannelInfo();
        } catch (ModelNotFoundException) {
            return null;
        }

        $actualPrice = (float) ($channelInfo?->price ?? 0);

        if ($actualPrice <= 0) {
            return null;
        }

        $dealerFee = $this->dealerFee();

        return new VehiclePrice(
            variant: $variant,
            vehicleKey: (string) $variant->sku,
            caCarsTotalPrice: round($actualPrice + $dealerFee, 2),
            ftcActualPrice: $actualPrice,
            mandatoryDealerFees: $dealerFee > 0 ? [['type' => self::DEALER_FEE_TYPE, 'amount' => $dealerFee]] : [],
            sourceSystem: self::SOURCE_SYSTEM,
            sourceVersion: (string) $channelInfo->channels_id,
        );
    }

    private function dealerFee(): float
    {
        return max(0.0, (float) ($this->lead->company->get(PriceDisclosureConfigurationEnum::DEALER_FEE->value) ?? 0));
    }

    private function leadVehicleVin(): ?string
    {
        $vehicleInterest = (array) ($this->lead->get(LeadCustomFieldEnum::VEHICLE_OF_INTEREST->value) ?? []);

        return Str::trimToNull((string) ($vehicleInterest['vin'] ?? ''));
    }
}
