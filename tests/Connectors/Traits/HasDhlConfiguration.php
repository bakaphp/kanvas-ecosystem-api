<?php

declare(strict_types=1);

namespace Tests\Connectors\Traits;

use Illuminate\Support\Facades\Http;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Dhl\Enums\ConfigurationEnum;
use Kanvas\Connectors\Dhl\Enums\CustomsAttributeEnum;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Products\Models\ProductsAttributes;
use Kanvas\Inventory\Variants\Models\Variants;
use Mockery;

trait HasDhlConfiguration
{
    public const string DHL_HOST = 'https://express.api.dhl.com/mydhlapi';
    public const string DHL_AWB = '1234567890';

    protected function configureDhl(Companies $company): void
    {
        $company->set(ConfigurationEnum::BASE_URL->value, self::DHL_HOST);
        $company->set(ConfigurationEnum::USERNAME->value, 'apiUser');
        $company->setEncrypted(ConfigurationEnum::PASSWORD->value, 'apiSecret');
        $company->set(ConfigurationEnum::ACCOUNT_NUMBER->value, '123456789');
        $company->set(ConfigurationEnum::SIGNATURE_SERVICE->value, 'SD');
        $company->set(ConfigurationEnum::LABEL_TEMPLATE->value, ConfigurationEnum::DEFAULT_LABEL_TEMPLATE);
        $company->set(ConfigurationEnum::REQUEST_PICKUP->value, 1);
        $company->set(ConfigurationEnum::ORIGIN->value, $this->dhlOrigin());
    }

    protected function customsVariant(string $productName, array $attributes, float $weight = 0.3): Variants
    {
        $product = Mockery::mock(Products::class)->makePartial();
        $product->name = $productName;

        foreach (CustomsAttributeEnum::cases() as $case) {
            $attribute = null;

            if (isset($attributes[$case->value])) {
                $attribute = Mockery::mock(ProductsAttributes::class)->makePartial();
                $attribute->shouldReceive('getAttribute')->with('value')->andReturn($attributes[$case->value]);
            }

            $product->shouldReceive('getAttributeBySlug')->with($case->value)->andReturn($attribute);
        }

        $variant = new Variants();
        $variant->weight = $weight;
        $variant->setRelation('product', $product);

        return $variant;
    }

    protected function completeCustomsData(): array
    {
        return [
            CustomsAttributeEnum::DESCRIPTION->value => 'Hand rolled cigars',
            CustomsAttributeEnum::MANUFACTURER_COUNTRY->value => 'do',
            CustomsAttributeEnum::HS_CODE->value => '240210',
        ];
    }

    protected function dhlOrigin(): array
    {
        return [
            'company_name' => 'Cabinet Store',
            'contact_name' => 'Warehouse Desk',
            'phone' => '+18095550100',
            'email' => 'warehouse@cabinet.test',
            'address_line1' => 'Av. Winston Churchill 100',
            'city' => 'Santo Domingo',
            'postal_code' => '10147',
            'country_code' => 'DO',
        ];
    }

    protected function dhlRatesResponse(): array
    {
        return [
            'products' => [
                [
                    'productName' => 'EXPRESS DOMESTIC',
                    'productCode' => 'N',
                    'localProductCode' => 'N',
                    'networkTypeCode' => 'DD',
                    'isCustomerAgreement' => false,
                    'weight' => ['volumetric' => 0.6, 'provided' => 1.2, 'unitOfMeasurements' => 'metric'],
                    'totalPrice' => [
                        ['currencyType' => 'BILLC', 'priceCurrency' => 'DOP', 'price' => 842.5],
                        ['currencyType' => 'PULCL', 'priceCurrency' => 'DOP', 'price' => 842.5],
                    ],
                    'deliveryCapabilities' => [
                        'deliveryTypeCode' => 'QDDC',
                        'estimatedDeliveryDateAndTime' => '2026-11-13T23:59:00GMT+00:00',
                        'destinationServiceAreaCode' => 'SDQ',
                        'totalTransitDays' => 1,
                    ],
                ],
                [
                    'productName' => 'EXPRESS 12:00 DOMESTIC',
                    'productCode' => '1',
                    'localProductCode' => '1',
                    'totalPrice' => [
                        ['currencyType' => 'BILLC', 'priceCurrency' => 'DOP', 'price' => 1190.0],
                    ],
                    'deliveryCapabilities' => ['totalTransitDays' => 1],
                ],
            ],
        ];
    }

    protected function dhlShipmentResponse(string $awb = self::DHL_AWB): array
    {
        return [
            'url' => 'https://express.api.dhl.com/mydhlapi/shipments',
            'shipmentTrackingNumber' => $awb,
            'trackingUrl' => "https://www.dhl.com/do-en/home/tracking.html?tracking-id={$awb}",
            'dispatchConfirmationNumber' => 'PRG-241110-0001',
            'cancelPickupUrl' => 'https://express.api.dhl.com/mydhlapi/pickups/PRG-241110-0001',
            'packages' => [['referenceNumber' => 1, 'trackingNumber' => 'JD014600003SE', 'trackingUrl' => 'https://www.dhl.com/']],
            'documents' => [[
                'imageFormat' => 'PDF',
                'content' => base64_encode('%PDF-1.4 fake dhl label'),
                'typeCode' => 'label',
            ]],
            'warnings' => [],
        ];
    }

    protected function dhlTrackingResponse(string $awb, array $events): array
    {
        return [
            'shipments' => [[
                'shipmentTrackingNumber' => $awb,
                'status' => 'transit',
                'description' => 'Shipment is in transit',
                'events' => $events,
            ]],
        ];
    }

    protected function dhlEvent(string $typeCode, string $date, string $time, string $description = 'Event', ?string $signedBy = null): array
    {
        return array_filter([
            'date' => $date,
            'time' => $time,
            'typeCode' => $typeCode,
            'description' => $description,
            'serviceArea' => [['code' => 'SDQ', 'description' => 'Santo Domingo - Dominican Republic']],
            'signedBy' => $signedBy,
        ]);
    }

    protected function dhlEpodResponse(): array
    {
        return [
            'documents' => [[
                'typeCode' => 'epod',
                'encodingFormat' => 'PDF',
                'content' => base64_encode('%PDF-1.4 fake dhl epod'),
            ]],
        ];
    }

    protected function fakeDhl(array $responses = []): void
    {
        Http::fake($responses + [self::DHL_HOST . '/*' => Http::response([], 500)]);
    }
}
