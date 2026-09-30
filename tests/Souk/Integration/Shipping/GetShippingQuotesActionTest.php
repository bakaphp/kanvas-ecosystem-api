<?php

declare(strict_types=1);

namespace Tests\Souk\Integration\Shipping;

use Baka\Contracts\CompanyInterface;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Cache;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Guild\Customers\Models\Address;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Inventory\Variants\Enums\ConfigurationEnum as VariantConfigurationEnum;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Models\VariantsAttributes;
use Kanvas\Locations\Models\Countries;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Shipping\Actions\BuildShipmentRequestAction;
use Kanvas\Souk\Shipping\Actions\GetShippingQuotesAction;
use Kanvas\Souk\Shipping\Contracts\ShippingRateProviderInterface;
use Kanvas\Souk\Shipping\DataTransferObject\ShipmentRequest;
use Kanvas\Souk\Shipping\DataTransferObject\ShippingDestination;
use Kanvas\Souk\Shipping\DataTransferObject\ShippingQuote;
use Kanvas\Souk\Shipping\Enums\ConfigurationEnum;
use Kanvas\Souk\Shipping\Providers\ShippingProviderFactory;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Models\Integrations;
use Mockery;
use RuntimeException;
use Tests\Souk\Concerns\ImportsShippingRateCards;
use Tests\TestCase;
use Wearepixel\Cart\Cart;

final class GetShippingQuotesActionTest extends TestCase
{
    use DatabaseTransactions;
    use ImportsShippingRateCards;

    protected $connectionsToTransact = [null, 'workflow', 'ecosystem', 'commerce'];

    public static array $quotesByProvider = [];
    public static array $callsByCompany = [];

    protected function setUp(): void
    {
        parent::setUp();

        self::$quotesByProvider = [];
        self::$callsByCompany = [];
        Cache::flush();

        foreach (['fake-a', 'fake-b', 'fake-failing'] as $name) {
            app()->bind("shipping_provider.{$name}", fn ($app, array $params) => $this->fakeProvider(
                $name,
                $params['company']
            ));
        }

        app()->instance(ShippingProviderFactory::API_PROVIDER_NAMES, ['fake-a', 'fake-b', 'fake-failing']);
    }

    public function testQuotesFromEveryProviderAreMergedAndSortedByAmount(): void
    {
        $company = $this->companyWithApiIntegration();
        self::$quotesByProvider['fake-a'] = [$this->quote('fake-a', 90.0)];
        self::$quotesByProvider['fake-b'] = [$this->quote('fake-b', 40.0), $this->quote('fake-b', 120.0)];
        $this->importRateCards($company, $this->rateCards());

        $quotes = $this->quotesFor($company, 500);

        $this->assertSame([40.0, 90.0, 100.0, 120.0], array_map(fn (ShippingQuote $q) => $q->amount, $quotes));
        $this->assertSame(
            ['fake-b', 'fake-a', 'inposdom', 'fake-b'],
            array_map(fn (ShippingQuote $q) => $q->provider, $quotes)
        );
    }

    public function testAFailingProviderDoesNotDropTheOthers(): void
    {
        $company = $this->companyWithApiIntegration();
        self::$quotesByProvider['fake-a'] = [$this->quote('fake-a', 90.0)];

        $quotes = $this->quotesFor($company, 500);

        $this->assertCount(1, $quotes);
        $this->assertSame('fake-a', $quotes[0]->provider);
    }

    public function testQuotesInAnotherCurrencyThanTheRegionAreDropped(): void
    {
        $company = $this->companyWithApiIntegration();
        self::$quotesByProvider['fake-a'] = [$this->quote('fake-a', 90.0, 'USD')];
        self::$quotesByProvider['fake-b'] = [$this->quote('fake-b', 40.0, 'DOP')];

        $quotes = $this->quotesFor($company, 500);

        $this->assertCount(1, $quotes);
        $this->assertSame('fake-b', $quotes[0]->provider);
    }

    public function testAPaddedRegionCurrencyCodeStillMatches(): void
    {
        $company = $this->companyWithApiIntegration();
        self::$quotesByProvider['fake-a'] = [$this->quote('fake-a', 90.0, 'DOP')];

        $request = $this->buildRequest([[1, 500, 1]], new ShippingDestination(countryCode: 'us'));
        $quotes = $this->runAction($company, $request, 'DOP ');

        $this->assertCount(1, $quotes);
        $this->assertSame('fake-a', $quotes[0]->provider);
    }

    public function testACartLineWithoutWeightYieldsNoRequestAndNoQuotes(): void
    {
        $company = $this->companyWithApiIntegration();
        self::$quotesByProvider['fake-a'] = [$this->quote('fake-a', 90.0)];

        $request = $this->buildRequest([[1, 500, 1], [2, 0, 1]], new ShippingDestination(countryCode: 'us'));
        $quotes = $this->runAction($company, $request);

        $this->assertNull($request);
        $this->assertSame([], $quotes);
        $this->assertSame([], self::$callsByCompany);
    }

    public function testAnUnknownDestinationCountryYieldsNoRequest(): void
    {
        $request = $this->buildRequest([[1, 500, 1]], new ShippingDestination(countryCode: 'zz-unknown'));

        $this->assertNull($request);
    }

    public function testTheAppFallbackDestinationAndDefaultBoxAreApplied(): void
    {
        $app = Mockery::mock(Apps::class)->makePartial();
        $app->shouldReceive('get')->andReturnUsing(fn (string $key): mixed => match ($key) {
            ConfigurationEnum::FALLBACK_DESTINATION_COUNTRY->value => 'us',
            ConfigurationEnum::DEFAULT_BOX_CM->value => ['length' => 30, 'width' => 20, 'height' => 10],
            default => null,
        });

        $request = $this->buildRequest([[1, 250, 2]], null, $app);

        $this->assertSame('us', $request->destinationCountry->code);
        $this->assertSame(500, $request->totalGrams());
        $this->assertSame(30.0, $request->parcels->toCollection()->first()->lengthCm);
    }

    public function testAnUppercaseExplicitCountryCodeResolvesTheCountry(): void
    {
        $request = $this->buildRequest([[1, 500, 1]], new ShippingDestination(countryCode: ' US '));

        $this->assertSame('us', $request->destinationCountry->code);
    }

    public function testTheDefaultAddressOfThePersonIsUsedWhenNoExplicitDestinationIsGiven(): void
    {
        $country = Countries::firstOrCreate(['code' => 'us'], ['name' => 'United States']);
        $address = new Address();
        $address->countries_id = $country->getKey();
        $address->city = 'Miami';
        $address->zip = '33101';
        $people = Mockery::mock(People::class)->makePartial();
        $people->shouldReceive('getDefaultAddress')->andReturn($address);

        $request = $this->buildRequest([[1, 500, 1]], null, people: $people);
        $quotes = $this->runAction($this->companyWithApiIntegration(), $request);

        $this->assertSame('us', $request->destinationCountry->code);
        $this->assertSame('33101', $request->destinationPostalCode);
        $this->assertSame([], $quotes);
    }

    public function testTwoCompaniesOnTheSameLaneGetSeparateCacheEntries(): void
    {
        $companyA = $this->companyWithApiIntegration();
        $companyB = $this->companyWithApiIntegration();
        self::$quotesByProvider['fake-a'] = [$this->quote('fake-a', 90.0)];

        $this->quotesFor($companyA, 500);
        $this->quotesFor($companyA, 500);
        $this->quotesFor($companyB, 500);

        $this->assertSame(1, self::$callsByCompany[$companyA->getId()]);
        $this->assertSame(1, self::$callsByCompany[$companyB->getId()]);
    }

    public function testAChangedWeightMissesTheCache(): void
    {
        $company = $this->companyWithApiIntegration();
        self::$quotesByProvider['fake-a'] = [$this->quote('fake-a', 90.0)];

        $this->quotesFor($company, 500);
        $this->quotesFor($company, 800);

        $this->assertSame(2, self::$callsByCompany[$company->getId()]);
    }

    private function quotesFor(Companies $company, int $grams): array
    {
        $request = $this->buildRequest([[1, $grams, 1]], new ShippingDestination(countryCode: 'us'));

        return $this->runAction($company, $request);
    }

    private function runAction(Companies $company, ?ShipmentRequest $request, string $regionCurrency = 'DOP'): array
    {
        $region = new Regions();
        $region->setRelation('currency', new Currencies()->forceFill(['code' => $regionCurrency]));

        return new GetShippingQuotesAction(
            app: app(Apps::class),
            company: $company,
            region: $region,
            request: $request,
        )->execute();
    }

    private function buildRequest(
        array $lines,
        ?ShippingDestination $destination,
        ?Apps $app = null,
        ?People $people = null
    ): ?ShipmentRequest {
        Countries::firstOrCreate(['code' => 'us'], ['name' => 'United States']);
        $cart = new Cart(
            new Store('shipping-cart', new ArraySessionHandler(120)),
            new Dispatcher(),
            'shipping-cart',
            'shipping-cart',
            ['driver' => 'session', 'format_numbers' => false, 'decimals' => 2, 'round_mode' => 'down'],
        );
        $weights = [];

        foreach ($lines as [$variantId, $grams, $quantity]) {
            $cart->add($variantId, "Variant {$variantId}", 10.0, $quantity);
            $weights[$variantId] = $grams;
        }

        $action = new class (
            $app ?? app(Apps::class),
            $cart,
            $destination,
            $people,
            $weights
        ) extends BuildShipmentRequestAction {
            public function __construct(
                Apps $app,
                Cart $cart,
                ?ShippingDestination $destination,
                ?People $people,
                private readonly array $weights
            ) {
                parent::__construct(
                    app: $app,
                    cart: $cart,
                    destination: $destination,
                    people: $people,
                );
            }

            protected function findVariant(int|string $id): Variants
            {
                $attribute = new VariantsAttributes();
                $attribute->value = $this->weights[$id] ?: null;
                $variant = Mockery::mock(Variants::class)->makePartial();
                $variant->shouldReceive('getAttributeByName')
                    ->with(VariantConfigurationEnum::WEIGHT_UNIT->value)
                    ->andReturn($attribute);

                return $variant;
            }
        };

        return $action->execute();
    }

    private function companyWithApiIntegration(): Companies
    {
        $company = Companies::factory()->create();
        $integration = Integrations::firstOrCreate(
            ['name' => IntegrationsEnum::YUSEN->value],
            [
                'apps_id' => 0,
                'handler' => 'Kanvas\\Connectors\\Fixture\\Handlers\\FixtureHandler',
            ]
        );
        $link = IntegrationsCompany::create([
            'companies_id' => $company->getId(),
            'integrations_id' => $integration->getId(),
            'status_id' => 1,
            'region_id' => 1,
        ]);
        $link->is_active = 1;
        $link->is_deleted = 0;
        $link->saveQuietly();

        return $company;
    }

    private function rateCards(): array
    {
        return [
            'inposdom' => [
                'zones' => ['US' => 'z1'],
                'services' => [
                    'ems' => [
                        'name' => 'EMS',
                        'currency' => 'DOP',
                        'fixed_charge' => 0,
                        'zones' => [
                            'z1' => [
                                'transit_min_days' => 3,
                                'transit_max_days' => 7,
                                'rates' => [['max_grams' => 1000, 'amount' => 100]],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function quote(string $provider, float $amount, string $currency = 'DOP'): ShippingQuote
    {
        return new ShippingQuote(
            provider: $provider,
            serviceCode: 'std',
            serviceName: 'Standard',
            amount: $amount,
            currency: $currency,
        );
    }

    private function fakeProvider(string $name, CompanyInterface $company): ShippingRateProviderInterface
    {
        return new class ($name, $company) implements ShippingRateProviderInterface {
            public function __construct(
                private readonly string $name,
                private readonly CompanyInterface $company,
            ) {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function integration(): ?IntegrationsEnum
            {
                return IntegrationsEnum::YUSEN;
            }

            public function supports(ShipmentRequest $request): bool
            {
                return true;
            }

            public function quote(ShipmentRequest $request): array
            {
                if ($this->name === 'fake-a') {
                    $companyId = $this->company->getId();
                    GetShippingQuotesActionTest::$callsByCompany[$companyId] =
                        (GetShippingQuotesActionTest::$callsByCompany[$companyId] ?? 0) + 1;
                }

                if ($this->name === 'fake-failing') {
                    throw new RuntimeException('carrier down');
                }

                return GetShippingQuotesActionTest::$quotesByProvider[$this->name] ?? [];
            }
        };
    }
}
