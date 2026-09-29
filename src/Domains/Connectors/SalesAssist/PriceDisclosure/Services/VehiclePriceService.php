<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\PriceDisclosure\Services;

use Baka\Support\Str;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Connectors\SalesAssist\Enums\LeadCustomFieldEnum;
use Kanvas\Connectors\SalesAssist\PriceDisclosure\DataTransferObject\VehiclePrice;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Inventory\Variants\Models\Variants;

class VehiclePriceService
{
    private const string SOURCE_SYSTEM = 'variant_channel';

    public function __construct(
        private readonly Lead $lead,
    ) {
    }

    /**
     * An explicit VIN from the conversation never falls back to the lead's vehicle of interest: a
     * miss on the unit the customer named must suppress, not quote another vehicle.
     */
    public function resolveVariant(?string $vin): ?Variants
    {
        $vin = Str::trimToNull($vin) ?? $this->leadVehicleVin();

        if ($vin === null) {
            return null;
        }

        $variant = Variants::fromApp($this->lead->app)
            ->fromCompany($this->lead->company)
            ->notDeleted()
            ->where('sku', $vin)
            ->first();

        return $variant instanceof Variants ? $variant : null;
    }

    private function leadVehicleVin(): ?string
    {
        $vehicleInterest = (array) ($this->lead->get(LeadCustomFieldEnum::VEHICLE_OF_INTEREST->value) ?? []);

        return Str::trimToNull((string) ($vehicleInterest['vin'] ?? ''));
    }

    /**
     * Until the dealer pricing feed exists, the default channel price stands in for both statutory
     * concepts and the fee breakdown is empty. Swapping the source later only touches this method.
     */
    public function priceFor(Variants $variant): ?VehiclePrice
    {
        try {
            $channelInfo = $variant->getChannelInfo();
        } catch (ModelNotFoundException) {
            return null;
        }

        $price = (float) ($channelInfo?->price ?? 0);

        if ($price <= 0) {
            return null;
        }

        return new VehiclePrice(
            variant: $variant,
            vehicleKey: (string) $variant->sku,
            caCarsTotalPrice: $price,
            ftcActualPrice: $price,
            mandatoryDealerFees: [],
            sourceSystem: self::SOURCE_SYSTEM,
            sourceVersion: (string) $channelInfo->channels_id,
        );
    }
}
