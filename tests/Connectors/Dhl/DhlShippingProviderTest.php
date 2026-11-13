<?php

declare(strict_types=1);

namespace Tests\Connectors\Dhl;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Dhl\Providers\DhlShippingProvider;
use Kanvas\Locations\Models\Countries;
use Kanvas\Souk\Shipping\DataTransferObject\Parcel;
use Kanvas\Souk\Shipping\DataTransferObject\ShipmentRequest;
use Kanvas\Souk\Shipping\Providers\ShippingProviderFactory;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Models\Integrations;
use Spatie\LaravelData\DataCollection;
use Tests\Connectors\Traits\HasDhlConfiguration;
use Tests\TestCase;

final class DhlShippingProviderTest extends TestCase
{
    use DatabaseTransactions;
    use HasDhlConfiguration;

    protected $connectionsToTransact = [null, 'workflow', 'ecosystem'];

    private Companies $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Companies::factory()->create();
        $this->configureDhl($this->company);
    }

    public function testQuotesDomesticLanesWithTheSignatureServiceInTheRatesBody(): void
    {
        $this->fakeDhl([self::DHL_HOST . '/rates' => Http::response($this->dhlRatesResponse())]);

        $quotes = $this->provider()->quote($this->request('DO'));

        $this->assertCount(2, $quotes);
        $this->assertSame('dhl', $quotes[0]->provider);
        $this->assertSame('N', $quotes[0]->serviceCode);
        $this->assertSame(842.5, $quotes[0]->amount);
        $this->assertSame('DOP', $quotes[0]->currency);
        $this->assertSame(1, $quotes[0]->transitMaxDays);
        $this->assertSame(['product_code' => 'N', 'local_product_code' => 'N', 'signature_code' => 'SD'], $quotes[0]->bookingMeta);

        Http::assertSent(function (Request $request): bool {
            return $request['valueAddedServices'] === [['serviceCode' => 'SD']]
                && $request['accounts'][0]['number'] === '123456789'
                && $request['customerDetails']['receiverDetails']['countryCode'] === 'DO'
                && $request['packages'][0]['dimensions'] === ['length' => 30.0, 'width' => 20.0, 'height' => 10.0]
                && $request['isCustomsDeclarable'] === false;
        });
    }

    public function testProductsWithoutAPricedCurrencyAreSkipped(): void
    {
        $response = $this->dhlRatesResponse();
        $response['products'][] = [
            'productName' => 'EXPRESS EASY',
            'productCode' => '7',
            'isCustomerAgreement' => true,
            'totalPrice' => [
                ['currencyType' => 'BILLC', 'price' => 0],
                ['currencyType' => 'PULCL', 'price' => 0],
            ],
        ];
        $this->fakeDhl([self::DHL_HOST . '/rates' => Http::response($response)]);

        $quotes = $this->provider()->quote($this->request('DO'));

        $this->assertCount(2, $quotes);
        $this->assertNotContains('7', array_map(fn ($quote): string => $quote->serviceCode, $quotes));
    }

    public function testInternationalLanesAreNotSupportedAndNeverCallDhl(): void
    {
        $this->fakeDhl();

        $request = $this->request('US');

        $this->assertFalse($this->provider()->supports($request));
        $this->assertSame([], $this->provider()->quote($request));
        Http::assertNothingSent();
    }

    public function testParcelsWithoutDimensionsAreNotSupported(): void
    {
        $this->fakeDhl();

        $request = $this->request('DO', withDimensions: false);

        $this->assertFalse($this->provider()->supports($request));
        $this->assertSame([], $this->provider()->quote($request));
        Http::assertNothingSent();
    }

    public function testACompanyWithoutAnOriginIsNotSupported(): void
    {
        $this->fakeDhl();
        $company = Companies::factory()->create();

        $provider = new DhlShippingProvider(app(Apps::class), $company);

        $this->assertFalse($provider->supports($this->request('DO')));
    }

    public function testTheFactoryOffersDhlOnlyWhileTheIntegrationIsActive(): void
    {
        $integration = Integrations::firstOrCreate(
            ['name' => IntegrationsEnum::DHL->value, 'apps_id' => 0],
            ['handler' => 'Kanvas\\Connectors\\Dhl\\Handlers\\DhlHandler']
        );
        $link = IntegrationsCompany::create([
            'companies_id' => $this->company->getId(),
            'integrations_id' => $integration->getId(),
            'status_id' => 1,
            'region_id' => 1,
        ]);
        $link->is_active = 1;
        $link->is_deleted = 0;
        $link->saveQuietly();

        $providers = ShippingProviderFactory::forCompany(app(Apps::class), $this->company);

        $this->assertCount(1, $providers);
        $this->assertSame('dhl', $providers[0]->name());

        $link->is_active = 0;
        $link->saveQuietly();

        $this->assertSame([], ShippingProviderFactory::forCompany(app(Apps::class), $this->company));
    }

    private function provider(): DhlShippingProvider
    {
        return new DhlShippingProvider(app(Apps::class), $this->company);
    }

    private function request(string $destinationCode, bool $withDimensions = true): ShipmentRequest
    {
        $country = Countries::query()->where('code', strtolower($destinationCode))->firstOrFail();

        return new ShipmentRequest(
            destinationCountry: $country,
            parcels: new DataCollection(Parcel::class, [
                new Parcel(
                    weight: 1.2,
                    lengthCm: $withDimensions ? 30.0 : null,
                    widthCm: $withDimensions ? 20.0 : null,
                    heightCm: $withDimensions ? 10.0 : null,
                ),
            ]),
            destinationCity: 'Santiago',
            destinationPostalCode: '51000',
        );
    }
}
