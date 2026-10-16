<?php

declare(strict_types=1);

namespace Tests\Insurance;

use Baka\Contracts\CompanyInterface;
use Closure;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Testing\TestResponse;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Humano\DataTransferObject\QuoteRequest as HumanoQuoteRequest;
use Kanvas\Connectors\Humano\Providers\HumanoProvider;
use Kanvas\Connectors\Humano\Services\HumanoService;
use Kanvas\Connectors\UniversalSeguros\DataTransferObject\QuoteRequest as UniversalQuoteRequest;
use Kanvas\Connectors\UniversalSeguros\Providers\UniversalSegurosProvider;
use Kanvas\Connectors\UniversalSeguros\Services\UniversalSegurosService;
use Kanvas\Insurance\DataTransferObject\CanonicalQuoteInput;
use Kanvas\Insurance\DataTransferObject\InsuranceSearchEntry;
use Kanvas\Insurance\Models\InsuranceVehicleModelMap;
use Kanvas\Insurance\Models\Insurer;
use Kanvas\Insurance\Services\InsuranceComparatorService;
use Kanvas\Insurance\Services\InsuranceQuoteBinding;
use Kanvas\Insurance\Services\InsuranceSearchService;
use Kanvas\Insurance\Services\InsuranceVehicleModelResolver;
use Kanvas\Insurance\Services\VehicleKeyNormalizer;
use Mockery;
use Tests\Insurance\Concerns\BuildsSearchCatalog;
use Tests\Insurance\Fakes\RecordingQuoteTaskRunner;
use Tests\TestCase;

class MultiProviderSearchTest extends TestCase
{
    use BuildsSearchCatalog;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'commerce', 'insurance'];

    private const SEARCH = '
        query($input: CanonicalQuoteInput!) {
            insuranceSearch(input: $input) {
                entries {
                    rank
                    insurer { name }
                    plan { id name type }
                    quote_number
                    price
                    premium
                    tax
                    total
                    currency
                    can_emit
                    is_sponsored
                    is_recommended
                    is_contractable
                }
                failures { insurer { name } reason message }
            }
        }
    ';

    private const UNIVERSAL_TOTALS = ['A-PA' => 6078.0, 'A-PC' => 4000.0, 'A-PT' => 3000.0, 'A-PL' => 1500.0];

    private const HUMANO_TOTALS = [
        'mi_auto_premier' => 90000.0,
        'mi_auto_full' => 50265.54,
        'mi_auto_basico' => 20000.0,
    ];

    private array $universalCalls = [];

    private array $humanoCalls = [];

    private Closure $universalRespond;

    private Closure $humanoRespond;

    protected function setUp(): void
    {
        parent::setUp();

        $this->universalRespond = fn (string $product): array => $this->universalQuote($product);
        $this->humanoRespond = fn (string $plan): array => $this->humanoQuote($plan);
    }

    private function universalQuote(string $product): array
    {
        $total = self::UNIVERSAL_TOTALS[$product];
        $premium = round($total / 1.16, 2);

        return [
            'numeroCotizacion' => 'U-' . $product,
            'data' => [
                'producto' => $product,
                'terminos' => ['prima' => $premium, 'impuesto' => round($total - $premium, 2), 'totalCobro' => $total],
            ],
        ];
    }

    private function humanoQuote(string $plan): array
    {
        $total = self::HUMANO_TOTALS[$plan];
        $premium = $plan === 'mi_auto_full' ? 43332.36 : round($total / 1.16, 2);
        $tax = $plan === 'mi_auto_full' ? 6933.18 : round($total - $premium, 2);

        return [
            'codigoError' => null,
            'fechaHasta' => '2026-11-07T00:00:00.000-04:00',
            'datos' => [['codigoDato' => 990010, 'valorDato' => '1', 'label' => 'Monedas', 'numeroBien' => 1]],
            'cotizaciones' => [[
                'numeroCotizacion' => 'H-' . $plan,
                'numeroItem' => 0,
                'codigoPlan' => 100,
                'descripcionPlan' => 'Vehículo',
                'montoPrimaAnual' => $premium,
                'montoComponenteAnual' => $tax,
                'montoTotalAnual' => $total,
                'montoPrimaProrrata' => $premium,
                'montoComponenteProrrata' => $tax,
                'montoTotalProrrata' => $total,
            ]],
        ];
    }

    private function bindRealAdapters(): void
    {
        $universal = Mockery::mock(UniversalSegurosService::class);
        $universal->shouldReceive('quote')->andReturnUsing(function (UniversalQuoteRequest $request): array {
            $this->universalCalls[] = $request->producto;

            return ($this->universalRespond)($request->producto, count($this->universalCalls));
        });

        $humano = Mockery::mock(HumanoService::class);
        $humano->shouldReceive('quote')->andReturnUsing(function (HumanoQuoteRequest $request): array {
            $this->humanoCalls[] = $request->plan->value;

            return ($this->humanoRespond)($request->plan->value, count($this->humanoCalls));
        });

        $this->app->bind(
            'insurance_provider.' . UniversalSegurosProvider::NAME,
            fn ($app, array $params): UniversalSegurosProvider => new UniversalSegurosProvider(
                app: $params['app'],
                company: $params['company'],
                service: $universal,
            )
        );
        $this->app->bind(
            'insurance_provider.' . HumanoProvider::NAME,
            fn ($app, array $params): HumanoProvider => new HumanoProvider(
                app: $params['app'],
                company: $params['company'],
                service: $humano,
            )
        );
    }

    private function service(?RecordingQuoteTaskRunner $runner = null): InsuranceSearchService
    {
        return new InsuranceSearchService(
            $runner ?? new RecordingQuoteTaskRunner(),
            new InsuranceVehicleModelResolver(),
            new InsuranceComparatorService(),
        );
    }

    private function model(): string
    {
        return 'Corolla ' . uniqid();
    }

    private function underwriting(array $override = []): array
    {
        return array_merge([
            'ip_address' => '10.0.0.1',
            'birth_date' => '1993-08-24',
            'marital_status' => 'single',
            'sex' => 'male',
            'usage' => 'private',
            'circulation_zone' => 'santo_domingo',
        ], $override);
    }

    private function input(
        CompanyInterface $company,
        string $model,
        ?array $underwriting = null,
        ?string $version = 'LE',
    ): CanonicalQuoteInput {
        return $this->searchInput(
            $company,
            model: $model,
            version: $version,
            underwriting: $underwriting ?? $this->underwriting(),
        );
    }

    private function seedMarket(
        CompanyInterface $company,
        string $model,
        array $universal,
        array $humano,
        array $versions = ['LE'],
        ?array $humanoMapped = null,
    ): array {
        $insurers = [];

        if ($universal !== []) {
            $insurers['universal'] = $this->seedInsurer($company, UniversalSegurosProvider::NAME);
            $this->mapUniversal($insurers['universal'], $model);

            foreach ($universal as $code => $attributes) {
                $this->seedPlan(
                    $insurers['universal'],
                    $code,
                    array_merge(['name' => UniversalSegurosProvider::NAME . ' ' . $code], $attributes)
                );
            }
        }

        if ($humano !== []) {
            $insurers['humano'] = $this->seedInsurer($company, HumanoProvider::NAME);

            foreach ($humano as $code => $attributes) {
                $this->seedPlan(
                    $insurers['humano'],
                    $code,
                    array_merge(['name' => HumanoProvider::NAME . ' ' . $code], $attributes)
                );
            }

            foreach ($humanoMapped ?? array_keys($humano) as $code) {
                foreach ($versions as $version) {
                    $this->mapHumano($insurers['humano'], $code, $model, $version);
                }
            }
        }

        return $insurers;
    }

    private function mapUniversal(Insurer $insurer, string $model): void
    {
        InsuranceVehicleModelMap::query()->create([
            'apps_id' => $insurer->apps_id,
            'insurers_id' => $insurer->getId(),
            'plan_code' => '',
            'brand_key' => 'toyota',
            'model_key' => VehicleKeyNormalizer::normalize($model),
            'version_key' => '',
            'year' => 0,
            'external_model_id' => '77',
            'source' => 'manual',
            'active' => true,
        ]);
    }

    private function mapHumano(Insurer $insurer, string $planCode, string $model, string $version): void
    {
        InsuranceVehicleModelMap::query()->create([
            'apps_id' => $insurer->apps_id,
            'insurers_id' => $insurer->getId(),
            'plan_code' => $planCode,
            'brand_key' => 'toyota',
            'model_key' => VehicleKeyNormalizer::normalize($model),
            'version_key' => VehicleKeyNormalizer::normalize($version),
            'year' => 0,
            'external_brand_id' => '2',
            'external_model_id' => '5',
            'external_version_id' => '7',
            'source' => 'manual',
            'active' => true,
        ]);
    }

    private function actingCompany(): CompanyInterface
    {
        $user = $this->createUser();
        $this->actingAs($user, 'api');
        $company = $user->getCurrentCompany();
        $this->pinPlatformCompany($company->getId());

        return $company;
    }

    private function searchOverGraphQL(string $model): TestResponse
    {
        return $this->graphQL(self::SEARCH, [
            'input' => [
                'category' => 'FULL',
                'vehicle' => [
                    'brand' => 'Toyota',
                    'model' => $model,
                    'version' => 'LE',
                    'year' => 2022,
                    'fuel_type' => 'GASOLINE_DIESEL',
                    'estimated_value' => 1000000,
                ],
                'underwriting' => $this->underwriting(),
            ],
        ])->assertSuccessful();
    }

    private function forbidden(): ClientException
    {
        return new ClientException(
            'Client error: `POST /cotizacion` resulted in a `403 Forbidden` response: Token invalido secret-xyz',
            new Request('POST', 'https://insurer.example.com/cotizacion'),
            new Response(403, [], 'Token invalido secret-xyz')
        );
    }

    public function testTheMergedListThroughGraphQLIsRankedAndBindsEveryQuoteToItsPlan(): void
    {
        $company = $this->actingCompany();
        $this->bindRealAdapters();
        $model = $this->model();
        $this->seedMarket(
            company: $company,
            model: $model,
            universal: ['A-PA' => ['recommended_score' => 5], 'A-PC' => [], 'A-PT' => []],
            humano: ['mi_auto_premier' => ['sponsored' => true], 'mi_auto_full' => ['commission_rate' => 10]],
        );

        $response = $this->searchOverGraphQL($model);

        $data = $response->json('data.insuranceSearch');
        $this->assertNotNull($data, json_encode($response->json()));
        $this->assertSame([], $data['failures']);
        $this->assertSame(
            ['humano mi_auto_premier', 'humano mi_auto_full', 'universal_seguros A-PA', 'universal_seguros A-PT', 'universal_seguros A-PC'],
            array_column(array_column($data['entries'], 'plan'), 'name')
        );
        $this->assertSame([1, 2, 3, 4, 5], array_column($data['entries'], 'rank'));
        $this->assertSame([true, false, false, false, false], array_column($data['entries'], 'is_sponsored'));
        $this->assertSame([false, false, true, false, false], array_column($data['entries'], 'is_recommended'));
        $this->assertSame([false, false, true, true, true], array_column($data['entries'], 'is_contractable'));
        $this->assertEquals([90000.0, 50265.54, 6078.0, 3000.0, 4000.0], array_column($data['entries'], 'price'));
        $this->assertSame(['FULL'], array_unique(array_column(array_column($data['entries'], 'plan'), 'type')));

        foreach ($data['entries'] as $entry) {
            $this->assertSame(
                $entry['plan']['id'],
                (string) InsuranceQuoteBinding::planIdFor(
                    app(Apps::class)->getId(),
                    auth()->user()->getId(),
                    $entry['quote_number']
                )
            );
        }
    }

    public function testUniversalFailingThroughGraphQLNeverLeaksInsurerDetail(): void
    {
        $company = $this->actingCompany();
        $this->bindRealAdapters();
        $this->universalRespond = fn () => throw $this->forbidden();
        $model = $this->model();
        $this->seedMarket(
            company: $company,
            model: $model,
            universal: ['A-PA' => []],
            humano: ['mi_auto_full' => []],
        );

        $response = $this->searchOverGraphQL($model);

        $data = $response->json('data.insuranceSearch');
        $this->assertNotNull($data, json_encode($response->json()));
        $this->assertCount(1, $data['entries']);
        $this->assertSame('humano mi_auto_full', $data['entries'][0]['plan']['name']);
        $this->assertCount(1, $data['failures']);
        $this->assertSame('UNAVAILABLE', $data['failures'][0]['reason']);
        $this->assertSame('This insurer is currently unavailable', $data['failures'][0]['message']);

        $body = strtolower((string) json_encode($response->json()));

        foreach (['secret-xyz', '403', 'forbidden', 'token', 'guzzle', 'cotizacion/', 'numerocotizacion', 'montoprima'] as $leak) {
            $this->assertStringNotContainsString($leak, $body);
        }
    }

    public function testASecondIdenticalSearchCallsNeitherRealAdapterAgain(): void
    {
        $company = $this->searchCompany();
        $this->bindRealAdapters();
        $model = $this->model();
        $this->seedMarket(
            company: $company,
            model: $model,
            universal: ['A-PA' => [], 'A-PC' => []],
            humano: ['mi_auto_full' => []],
        );
        $input = $this->input($company, $model);

        $first = $this->service()->execute($input);
        $second = $this->service()->execute($input);

        $this->assertCount(2, $this->universalCalls);
        $this->assertCount(1, $this->humanoCalls);
        $this->assertSame(
            array_map(fn (InsuranceSearchEntry $entry): string => $entry->quote->quoteNumber, $first->entries),
            array_map(fn (InsuranceSearchEntry $entry): string => $entry->quote->quoteNumber, $second->entries)
        );
        $this->assertSame([], $second->failures);
    }
}
