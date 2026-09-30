<?php

declare(strict_types=1);

namespace Tests\Souk\Integration\Shipping;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\AppKey;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Locations\Models\Countries;
use Kanvas\Souk\Shipping\DataTransferObject\Parcel;
use Kanvas\Souk\Shipping\DataTransferObject\ShipmentRequest;
use Kanvas\Souk\Shipping\DataTransferObject\ShippingQuote;
use Kanvas\Souk\Shipping\Providers\ShippingProviderFactory;
use Kanvas\Souk\Shipping\RateCards\Models\RateCard;
use Kanvas\Souk\Shipping\RateCards\Models\RateCardCountry;
use Kanvas\Souk\Shipping\RateCards\RateCardShippingProvider;
use Spatie\LaravelData\DataCollection;
use Tests\Souk\Concerns\ImportsShippingRateCards;
use Tests\TestCase;

final class RateCardShippingProviderTest extends TestCase
{
    use DatabaseTransactions;
    use ImportsShippingRateCards;

    protected $connectionsToTransact = [null, 'ecosystem', 'commerce'];

    public function testQuoteAtTheExactBracketEdgeChargesThatBracketPlusTheFixedCharge(): void
    {
        $provider = $this->providerFor($this->companyWithRateCards($this->rateCards()));

        $quotes = $provider->quote($this->request('US', 500));

        $this->assertTrue($provider->supports($this->request('US', 500)));
        $this->assertCount(1, $quotes);
        $this->assertSame(150.0, $quotes[0]->amount);
        $this->assertSame('DOP', $quotes[0]->currency);
        $this->assertSame('EMS', $quotes[0]->serviceName);
    }

    public function testQuoteOneGramOverTheEdgeRollsToTheNextBracket(): void
    {
        $provider = $this->providerFor($this->companyWithRateCards($this->rateCards()));

        $quotes = $provider->quote($this->request('US', 501));

        $this->assertCount(1, $quotes);
        $this->assertSame(200.0, $quotes[0]->amount);
    }

    public function testQuoteOverTheMaxBracketIsUnsupported(): void
    {
        $provider = $this->providerFor($this->companyWithRateCards($this->rateCards()));
        $request = $this->request('US', 1001);

        $this->assertFalse($provider->supports($request));
        $this->assertSame([], $provider->quote($request));
    }

    public function testQuoteForAnUnmappedCountryIsUnsupported(): void
    {
        $provider = $this->providerFor($this->companyWithRateCards($this->rateCards()));
        $request = $this->request('ES', 500);

        $this->assertFalse($provider->supports($request));
        $this->assertSame([], $provider->quote($request));
    }

    public function testQuoteForAZeroWeightShipmentIsUnsupported(): void
    {
        $provider = $this->providerFor($this->companyWithRateCards($this->rateCards()));

        $this->assertSame([], $provider->quote($this->request('US', 0)));
    }

    public function testQuoteWithNoRateCardsIsUnsupported(): void
    {
        $provider = $this->providerFor(Companies::factory()->create());
        $request = $this->request('US', 500);

        $this->assertFalse($provider->supports($request));
        $this->assertSame([], $provider->quote($request));
    }

    public function testQuoteReturnsOneQuotePerServiceUnderTheSameProvider(): void
    {
        $cards = $this->rateCards();
        $cards['inposdom']['services']['correo_certificado'] = $this->service('Correo Certificado', 10, [
            'z1' => $this->zoneEntry([['max_grams' => 500, 'amount' => 60]]),
        ]);
        $provider = $this->providerFor($this->companyWithRateCards($cards));

        $quotes = $provider->quote($this->request('US', 500));

        $this->assertCount(2, $quotes);
        $this->assertEqualsCanonicalizing(
            ['ems', 'correo_certificado'],
            array_map(fn (ShippingQuote $quote) => $quote->serviceCode, $quotes)
        );
    }

    public function testQuoteMatchesALowercaseCountryKeyInTheImport(): void
    {
        $cards = $this->rateCards();
        $cards['inposdom']['zones'] = ['us' => 'z1'];
        $provider = $this->providerFor($this->companyWithRateCards($cards));

        $quotes = $provider->quote($this->request('US', 500));

        $this->assertCount(1, $quotes);
        $this->assertSame(150.0, $quotes[0]->amount);
    }

    public function testQuoteMatchesAnUppercaseCountryKeyInTheImport(): void
    {
        $provider = $this->providerFor($this->companyWithRateCards($this->rateCards()));

        $quotes = $provider->quote($this->request('US', 500));

        $this->assertCount(1, $quotes);
    }

    public function testQuoteIsScopedToTheProvidersOwnCompany(): void
    {
        $companyA = Companies::factory()->create();
        $this->companyWithRateCards($this->rateCards());
        $provider = $this->providerFor($companyA);
        $request = $this->request('US', 500);

        $this->assertFalse($provider->supports($request));
        $this->assertSame([], $provider->quote($request));
    }

    public function testQuoteTakesTransitFromTheMatchedZoneAndBracket(): void
    {
        $cards = $this->rateCards();
        $cards['inposdom']['zones']['ES'] = 'z2';
        $cards['inposdom']['services']['ems']['zones']['z1'] = $this->zoneEntry(
            [
                ['max_grams' => 500, 'amount' => 100],
                ['max_grams' => 1000, 'amount' => 150],
            ],
            7,
            10
        );
        $cards['inposdom']['services']['ems']['zones']['z2'] = $this->zoneEntry(
            [['max_grams' => 500, 'amount' => 120]],
            9,
            12
        );
        $provider = $this->providerFor($this->companyWithRateCards($cards));

        $us500 = $provider->quote($this->request('US', 500))[0];
        $us501 = $provider->quote($this->request('US', 501))[0];
        $es500 = $provider->quote($this->request('ES', 500))[0];

        $this->assertSame([7, 10], [$us500->transitMinDays, $us500->transitMaxDays]);
        $this->assertSame([7, 10], [$us501->transitMinDays, $us501->transitMaxDays]);
        $this->assertSame([9, 12], [$es500->transitMinDays, $es500->transitMaxDays]);
    }

    public function testQuotePicksTheSmallestMatchingBracketWhenRatesAreUnsorted(): void
    {
        $cards = $this->rateCards();
        $cards['inposdom']['services']['ems']['zones']['z1'] = $this->zoneEntry([
            ['max_grams' => 2000, 'amount' => 300],
            ['max_grams' => 500, 'amount' => 100],
            ['max_grams' => 1000, 'amount' => 150],
        ]);
        $provider = $this->providerFor($this->companyWithRateCards($cards));

        $quotes = $provider->quote($this->request('US', 400));

        $this->assertSame(150.0, $quotes[0]->amount);
    }

    public function testForCompanyBuildsOneRateCardProviderPerImportedKeyScopedToTheCompany(): void
    {
        $app = app(Apps::class);
        $companyA = $this->companyWithRateCards(
            $this->rateCards() + ['other' => $this->rateCards()['inposdom']]
        );
        $companyB = Companies::factory()->create();

        $providersA = ShippingProviderFactory::forCompany($app, $companyA);
        $providersB = ShippingProviderFactory::forCompany($app, $companyB);

        $this->assertSame(['inposdom', 'other'], array_map(fn ($provider) => $provider->name(), $providersA));
        $this->assertSame([], $providersB);
    }

    public function testQuoteExcludesASoftDeletedRateAndFallsToTheNextBracket(): void
    {
        $company = $this->companyWithRateCards($this->rateCards());
        $card = RateCard::query()->fromCompany($company)->firstOrFail();
        $card->rates()->where('max_grams', 500)->firstOrFail()->delete();
        $provider = $this->providerFor($company);

        $quotes = $provider->quote($this->request('US', 500));

        $this->assertSame(200.0, $quotes[0]->amount);
        $this->assertSame(1000, $quotes[0]->meta['max_grams']);
    }

    public function testQuoteExcludesASoftDeletedCardAndItsCountryMapping(): void
    {
        $company = $this->companyWithRateCards($this->rateCards());
        RateCard::query()->fromCompany($company)->firstOrFail()->delete();
        $provider = $this->providerFor($company);

        $this->assertSame([], $provider->quote($this->request('US', 500)));
        $this->assertSame([], ShippingProviderFactory::forCompany(app(Apps::class), $company));

        RateCardCountry::query()->fromCompany($company)->firstOrFail()->delete();

        $this->assertSame([], $provider->quote($this->request('US', 500)));
    }

    public function testQuoteExposesTheZoneAndMatchedBracketInMeta(): void
    {
        $provider = $this->providerFor($this->companyWithRateCards($this->rateCards()));

        $quote = $provider->quote($this->request('US', 501))[0];

        $this->assertSame(['zone' => 'z1', 'max_grams' => 1000], $quote->meta);
    }

    public function testAnAppKeyContextWithoutABranchNeverLeaksAnotherCompanysCardsZonesOrPrices(): void
    {
        $app = app(Apps::class);
        $companyA = $this->companyWithRateCards($this->rateCards());
        $cardsB = $this->rateCards();
        $cardsB['inposdom']['zones'] = ['US' => 'z1', 'ES' => 'z1'];
        $cardsB['inposdom']['services']['ems']['zones']['z1']['rates'][0]['amount'] = 9999;
        $cardsB['spy'] = $cardsB['inposdom'];
        $this->companyWithRateCards($cardsB);
        app()->instance(AppKey::class, $app->keys()->firstOrFail());
        $this->assertFalse(app()->bound(CompaniesBranches::class));

        $providersA = ShippingProviderFactory::forCompany($app, $companyA);
        $providerA = $this->providerFor($companyA);

        $this->assertSame(['inposdom'], array_map(fn ($provider) => $provider->name(), $providersA));
        $this->assertSame(150.0, $providerA->quote($this->request('US', 500))[0]->amount);
        $this->assertSame([], $providerA->quote($this->request('ES', 500)));
    }

    public function testTheSeededInposdomRateCardQuotesEmsAndCorreoCertificado(): void
    {
        $provider = $this->providerFor($this->companyWithRateCards($this->inposdomRateCardsFromJson()));

        $ems = $this->quoteFor($provider->quote($this->request('US', 500)), 'ems');
        $certificado = $this->quoteFor($provider->quote($this->request('ES', 300)), 'correo_certificado');
        $lightCertificado = $this->quoteFor($provider->quote($this->request('US', 50)), 'correo_certificado');
        $heavyResto = $this->quoteFor($provider->quote($this->request('CN', 12500)), 'ems');

        $this->assertSame(2820.0, $ems->amount);
        $this->assertSame([7, 10], [$ems->transitMinDays, $ems->transitMaxDays]);
        $this->assertSame(450.0, $certificado->amount);
        $this->assertSame([11, 14], [$certificado->transitMinDays, $certificado->transitMaxDays]);
        $this->assertSame(375.0, $lightCertificado->amount);
        $this->assertSame(11720.0, $heavyResto->amount);
        $this->assertSame([10, 15], [$heavyResto->transitMinDays, $heavyResto->transitMaxDays]);
    }

    private function quoteFor(array $quotes, string $serviceCode): ShippingQuote
    {
        $matching = array_values(array_filter(
            $quotes,
            fn (ShippingQuote $quote) => $quote->serviceCode === $serviceCode
        ));

        $this->assertCount(1, $matching);

        return $matching[0];
    }

    private function rateCards(): array
    {
        return [
            'inposdom' => [
                'zones' => ['US' => 'z1'],
                'services' => [
                    'ems' => $this->service('EMS', 50, [
                        'z1' => $this->zoneEntry([
                            ['max_grams' => 500, 'amount' => 100],
                            ['max_grams' => 1000, 'amount' => 150],
                        ]),
                    ]),
                ],
            ],
        ];
    }

    private function service(string $name, float $fixedCharge, array $zones): array
    {
        return [
            'name' => $name,
            'currency' => 'DOP',
            'fixed_charge' => $fixedCharge,
            'zones' => $zones,
        ];
    }

    private function zoneEntry(array $rates, int $transitMinDays = 3, int $transitMaxDays = 7): array
    {
        return [
            'transit_min_days' => $transitMinDays,
            'transit_max_days' => $transitMaxDays,
            'rates' => $rates,
        ];
    }

    private function providerFor(Companies $company): RateCardShippingProvider
    {
        return new RateCardShippingProvider('inposdom', app(Apps::class), $company);
    }

    private function companyWithRateCards(array $cards): Companies
    {
        $company = Companies::factory()->create();
        $this->importRateCards($company, $cards);

        return $company;
    }

    private function request(string $countryCode, int $grams): ShipmentRequest
    {
        $country = Countries::firstOrCreate(['code' => $countryCode], ['name' => $countryCode]);

        return new ShipmentRequest(
            destinationCountry: $country,
            parcels: Parcel::collect([new Parcel(grams: $grams)], DataCollection::class),
        );
    }
}
