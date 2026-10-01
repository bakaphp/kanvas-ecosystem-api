<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\PriceDisclosure\Services;

use Baka\Support\Str;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Connectors\SalesAssist\Enums\LeadCustomFieldEnum;
use Kanvas\Connectors\SalesAssist\PriceDisclosure\DataTransferObject\VehiclePrice;
use Kanvas\Connectors\SalesAssist\PriceDisclosure\Enums\PriceDisclosureConfigurationEnum;
use Kanvas\Connectors\SalesAssist\PriceDisclosure\Enums\PriceDisclosureFeeEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Models\VariantsChannels;

class VehiclePriceService
{
    private const string SOURCE_ATTRIBUTES = 'variant_attributes';

    private const string SOURCE_CHANNEL = 'variant_channel';

    /** Slug of the advertised price attribute; Str::slug turns "internet_price" into "internet-price". */
    private const array INTERNET_PRICE_SLUGS = ['internet-price', 'internet_price'];

    private const array MSRP_SLUGS = ['msrp'];

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
     * The advertised price is the variant's internet_price attribute, falling back to the stored
     * channel price for inventories that have not been enriched yet. The MSRP attribute feeds the
     * CA CARS total and falls back to the advertised price when the unit has none.
     */
    public function priceFor(Variants $variant): ?VehiclePrice
    {
        $internetPrice = $this->attributeAmount($variant, self::INTERNET_PRICE_SLUGS);
        $channelInfo = $internetPrice === null ? $this->channelInfo($variant) : null;
        $internetPrice ??= self::amount($channelInfo?->price);

        if ($internetPrice === null) {
            return null;
        }

        $msrp = $this->attributeAmount($variant, self::MSRP_SLUGS) ?? $internetPrice;
        $fees = $this->fees();
        $feesTotal = array_sum(array_column($fees, 'amount'));

        return new VehiclePrice(
            variant: $variant,
            vehicleKey: (string) $variant->sku,
            caCarsTotalPrice: $msrp,
            preRebateSellingPrice: round($msrp + $feesTotal, 2),
            ftcActualPrice: round($internetPrice + $feesTotal, 2),
            mandatoryDealerFees: $fees,
            sourceSystem: $channelInfo === null ? self::SOURCE_ATTRIBUTES : self::SOURCE_CHANNEL,
            sourceVersion: $channelInfo === null ? null : (string) $channelInfo->channels_id,
        );
    }

    /**
     * @return array<int, array{type: string, amount: float}>
     */
    private function fees(): array
    {
        $configured = Str::jsonToArray($this->lead->company->get(PriceDisclosureConfigurationEnum::FEES->value));
        $configured = is_array($configured) ? $configured : [];

        return array_map(
            fn (PriceDisclosureFeeEnum $fee): array => [
                'type' => $fee->value,
                'amount' => max(0.0, (float) ($configured[$fee->value] ?? 0)),
            ],
            PriceDisclosureFeeEnum::cases()
        );
    }

    private function attributeAmount(Variants $variant, array $slugs): ?float
    {
        foreach ([$variant, $variant->product] as $entity) {
            $amount = self::amount($entity?->attributes()->whereIn('attributes.slug', $slugs)->first()?->value);

            if ($amount !== null) {
                return $amount;
            }
        }

        return null;
    }

    private function channelInfo(Variants $variant): ?VariantsChannels
    {
        try {
            return $variant->getChannelInfo();
        } catch (ModelNotFoundException) {
            return null;
        }
    }

    /**
     * Feed values arrive as "27,500" or "$27500.00" as often as plain numbers; anything that is not a
     * positive amount after normalizing counts as missing.
     */
    private static function amount(mixed $value): ?float
    {
        if ($value === null || is_array($value)) {
            return null;
        }

        $normalized = str_replace(['$', ','], '', trim((string) $value));

        return is_numeric($normalized) && (float) $normalized > 0 ? (float) $normalized : null;
    }

    private function leadVehicleVin(): ?string
    {
        $vehicleInterest = (array) ($this->lead->get(LeadCustomFieldEnum::VEHICLE_OF_INTEREST->value) ?? []);

        return Str::trimToNull((string) ($vehicleInterest['vin'] ?? ''));
    }
}
