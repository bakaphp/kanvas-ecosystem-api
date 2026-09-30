<?php

declare(strict_types=1);

namespace Tests\Souk\Unit;

use Kanvas\Locations\Models\Countries;
use Kanvas\Souk\Shipping\DataTransferObject\Parcel;
use Kanvas\Souk\Shipping\DataTransferObject\ShipmentRequest;
use Kanvas\Souk\Shipping\DataTransferObject\ShippingQuote;
use Spatie\LaravelData\DataCollection;
use Tests\TestCaseUnit;

final class ShippingDataTransferObjectTest extends TestCaseUnit
{
    public function testTotalGramsSumsEveryParcelInTheRequest(): void
    {
        $request = new ShipmentRequest(
            destinationCountry: new Countries(['code' => 'US']),
            parcels: Parcel::collect(
                [
                    new Parcel(grams: 500),
                    new Parcel(grams: 250, lengthCm: 20.5, widthCm: 15.0, heightCm: 10.0),
                ],
                DataCollection::class
            ),
        );

        $this->assertSame(750, $request->totalGrams());
    }

    public function testTotalGramsWithASingleParcel(): void
    {
        $request = new ShipmentRequest(
            destinationCountry: new Countries(['code' => 'ES']),
            parcels: Parcel::collect([new Parcel(grams: 1200)], DataCollection::class),
            destinationCity: 'Madrid',
            destinationPostalCode: '28001',
        );

        $this->assertSame(1200, $request->totalGrams());
    }

    public function testShippingQuoteConstructionKeepsProviderAndServiceIdentity(): void
    {
        $quote = new ShippingQuote(
            provider: 'inposdom',
            serviceCode: 'ems',
            serviceName: 'EMS',
            amount: 850.0,
            currency: 'DOP',
            transitMinDays: 3,
            transitMaxDays: 7,
            meta: ['zone' => '1'],
        );

        $this->assertSame('inposdom', $quote->provider);
        $this->assertSame('ems', $quote->serviceCode);
        $this->assertSame(850.0, $quote->amount);
        $this->assertSame('DOP', $quote->currency);
        $this->assertSame(3, $quote->transitMinDays);
        $this->assertSame(7, $quote->transitMaxDays);
        $this->assertSame(['zone' => '1'], $quote->meta);
    }

    public function testShippingQuoteDefaultsTransitDaysAndMetaWhenOmitted(): void
    {
        $quote = new ShippingQuote(
            provider: 'inposdom',
            serviceCode: 'certificado',
            serviceName: 'Correo Certificado',
            amount: 400.0,
            currency: 'DOP',
        );

        $this->assertNull($quote->transitMinDays);
        $this->assertNull($quote->transitMaxDays);
        $this->assertSame([], $quote->meta);
    }
}
