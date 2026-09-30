<?php

declare(strict_types=1);

namespace Kanvas\Souk\Shipping\Actions;

use Baka\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Inventory\Variants\Enums\ConfigurationEnum as VariantConfigurationEnum;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Locations\Models\Countries;
use Kanvas\Souk\Shipping\DataTransferObject\Parcel;
use Kanvas\Souk\Shipping\DataTransferObject\ShipmentRequest;
use Kanvas\Souk\Shipping\DataTransferObject\ShippingDestination;
use Kanvas\Souk\Shipping\Enums\ConfigurationEnum;
use Spatie\LaravelData\DataCollection;
use Wearepixel\Cart\Cart;

class BuildShipmentRequestAction
{
    public function __construct(
        protected Apps $app,
        protected Cart $cart,
        protected ?ShippingDestination $destination = null,
        protected ?People $people = null,
    ) {
    }

    public function execute(): ?ShipmentRequest
    {
        $grams = $this->totalGrams();
        $resolved = $this->resolveDestination();

        if ($grams === null || $resolved === null) {
            return null;
        }

        [$destination, $country] = $resolved;

        return new ShipmentRequest(
            destinationCountry: $country,
            parcels: new DataCollection(Parcel::class, [$this->buildParcel($grams)]),
            destinationCity: $destination->city,
            destinationPostalCode: $destination->postalCode,
        );
    }

    protected function findVariant(int|string $id): Variants
    {
        return Variants::getById($id, $this->app);
    }

    private function totalGrams(): ?int
    {
        $items = $this->cart->getContent();

        if ($items->isEmpty()) {
            return null;
        }

        $total = 0.0;

        foreach ($items as $item) {
            $unitGrams = (float) $this->findVariant($item['id'])
                ->getAttributeByName(VariantConfigurationEnum::WEIGHT_UNIT->value)?->value;

            if ($unitGrams <= 0) {
                return null;
            }

            $total += $unitGrams * (float) $item['quantity'];
        }

        return (int) ceil($total);
    }

    private function resolveDestination(): ?array
    {
        if ($this->destination !== null) {
            return $this->withCountry($this->destination);
        }

        return $this->destinationFromDefaultAddress() ?? $this->fallbackDestination();
    }

    private function destinationFromDefaultAddress(): ?array
    {
        $address = $this->people?->getDefaultAddress();
        $country = $address?->countries_id ? Countries::find($address->countries_id) : null;

        if ($address === null || $country === null) {
            return null;
        }

        return [
            new ShippingDestination(
                countryCode: $country->code,
                city: Str::trimToNull($address->city),
                postalCode: Str::trimToNull($address->zip),
            ),
            $country,
        ];
    }

    private function fallbackDestination(): ?array
    {
        $countryCode = Str::trimmedStringOrNull($this->app->get(ConfigurationEnum::FALLBACK_DESTINATION_COUNTRY->value));

        return $countryCode === null ? null : $this->withCountry(new ShippingDestination(countryCode: $countryCode));
    }

    private function withCountry(ShippingDestination $destination): ?array
    {
        $code = Str::trimToNull($destination->countryCode);
        $country = $code === null ? null : Countries::query()->where('code', strtolower($code))->first();

        return $country === null ? null : [$destination, $country];
    }

    private function buildParcel(int $grams): Parcel
    {
        $box = $this->app->get(ConfigurationEnum::DEFAULT_BOX_CM->value);

        if (! is_array($box)) {
            return new Parcel(grams: $grams);
        }

        return new Parcel(
            grams: $grams,
            lengthCm: isset($box['length']) ? (float) $box['length'] : null,
            widthCm: isset($box['width']) ? (float) $box['width'] : null,
            heightCm: isset($box['height']) ? (float) $box['height'] : null,
        );
    }
}
