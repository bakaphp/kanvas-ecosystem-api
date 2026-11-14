<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Dhl\Providers;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Dhl\Actions\BuildDhlRatesPayloadAction;
use Kanvas\Connectors\Dhl\Client;
use Kanvas\Connectors\Dhl\Enums\ConfigurationEnum;
use Kanvas\Connectors\Dhl\Enums\CustomsAttributeEnum;
use Kanvas\Souk\Shipping\Contracts\ShippingRateProviderInterface;
use Kanvas\Souk\Shipping\DataTransferObject\Parcel;
use Kanvas\Souk\Shipping\DataTransferObject\ShipmentRequest;
use Kanvas\Souk\Shipping\DataTransferObject\ShippingQuote;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Override;

class DhlShippingProvider implements ShippingRateProviderInterface
{
    public const string NAME = 'dhl';
    public const string BILLING_CURRENCY_TYPE = 'BILLC';

    public function __construct(
        private readonly AppInterface $app,
        private readonly CompanyInterface $company,
    ) {
    }

    #[Override]
    public function name(): string
    {
        return self::NAME;
    }

    #[Override]
    public function integration(): ?IntegrationsEnum
    {
        return IntegrationsEnum::DHL;
    }

    #[Override]
    public function supports(ShipmentRequest $request): bool
    {
        $origin = $this->origin();

        if ($origin === [] || $request->parcels->count() === 0) {
            return false;
        }

        if (BuildDhlRatesPayloadAction::crossesBorder($this->company, $request->destinationCountry->code)
            && CustomsAttributeEnum::missingByProduct($request->variants) !== []) {
            return false;
        }

        return $request->parcels->toCollection()->every(fn (Parcel $parcel): bool => $this->hasDimensions($parcel));
    }

    #[Override]
    public function quote(ShipmentRequest $request): array
    {
        if (! $this->supports($request)) {
            return [];
        }

        $payload = new BuildDhlRatesPayloadAction(
            company: $this->company,
            parcels: $request->parcels->toCollection()->map(fn (Parcel $parcel): array => [
                'weight' => $parcel->weight,
                'length' => $parcel->lengthCm,
                'width' => $parcel->widthCm,
                'height' => $parcel->heightCm,
            ])->all(),
            destination: [
                'postal_code' => $request->destinationPostalCode,
                'city' => $request->destinationCity,
                'country_code' => $request->destinationCountry->code,
            ],
            shipDate: $request->shipDate,
        );

        $response = new Client($this->company)->rates($payload->execute());

        return array_values(array_filter(array_map(
            fn (array $product): ?ShippingQuote => $this->buildQuote($product, $payload->signatureService()),
            $response['products'] ?? []
        )));
    }

    private function buildQuote(array $product, string $signatureCode): ?ShippingQuote
    {
        $price = $this->billingPrice($product);

        if ($price === null) {
            return null;
        }

        $transitDays = $product['deliveryCapabilities']['totalTransitDays'] ?? null;

        return new ShippingQuote(
            provider: self::NAME,
            serviceCode: (string) $product['productCode'],
            serviceName: (string) ($product['productName'] ?? $product['productCode']),
            amount: round((float) $price['price'], 2),
            currency: (string) $price['priceCurrency'],
            transitMinDays: $transitDays === null ? null : (int) $transitDays,
            transitMaxDays: $transitDays === null ? null : (int) $transitDays,
            bookingMeta: [
                'product_code' => (string) $product['productCode'],
                'local_product_code' => $product['localProductCode'] ?? null,
                'signature_code' => $signatureCode,
            ],
        );
    }

    private function billingPrice(array $product): ?array
    {
        $prices = array_values(array_filter(
            $product['totalPrice'] ?? [],
            fn (array $price): bool => ! empty($price['priceCurrency']) && (float) ($price['price'] ?? 0) > 0
        ));

        foreach ($prices as $price) {
            if (($price['currencyType'] ?? null) === self::BILLING_CURRENCY_TYPE) {
                return $price;
            }
        }

        return $prices[0] ?? null;
    }

    private function origin(): array
    {
        $origin = $this->company->get(ConfigurationEnum::ORIGIN->value);

        return is_array($origin) ? $origin : [];
    }

    private function hasDimensions(Parcel $parcel): bool
    {
        return $parcel->lengthCm !== null && $parcel->widthCm !== null && $parcel->heightCm !== null;
    }
}
