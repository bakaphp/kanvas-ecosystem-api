<?php

declare(strict_types=1);

namespace Tests\Insurance;

use Baka\Contracts\CompanyInterface;
use Closure;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
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
use Kanvas\Exceptions\ValidationException;
use Kanvas\Insurance\DataTransferObject\CanonicalQuoteInput;
use Kanvas\Insurance\DataTransferObject\InsuranceSearchEntry;
use Kanvas\Insurance\DataTransferObject\InsuranceSearchFailure;
use Kanvas\Insurance\Enums\InsuranceSearchFailureReasonEnum;
use Kanvas\Insurance\Models\InsuranceVehicleModelMap;
use Kanvas\Insurance\Models\Insurer;
use Kanvas\Insurance\Providers\InsuranceProviderFactory;
use Kanvas\Insurance\Services\InsuranceComparatorService;
use Kanvas\Insurance\Services\InsuranceQuoteBinding;
use Kanvas\Insurance\Services\InsuranceSearchService;
use Kanvas\Insurance\Services\InsuranceVehicleModelResolver;
use Kanvas\Insurance\Services\VehicleKeyNormalizer;
use Laravel\Octane\Exceptions\TaskTimeoutException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Insurance\Concerns\BuildsSearchCatalog;
use Tests\Insurance\Fakes\RecordingQuoteTaskRunner;
use Tests\TestCase;
use Throwable;

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

    private function humanoBusinessError(): array
    {
        return [
            'codigoError' => 'EL VALOR MINIMO PARA ESTE VEHICULO ES DE: 781,952.00 RD$ Y EL VALOR MAXIMO ES DE: 1,172,928.00 RD$  cot 2256835224 en 120.2',
            'fechaHasta' => '2026-11-07T00:00:00.000-04:00',
            'datos' => [],
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

    private function planNames(array $entries): array
    {
        return array_map(fn (InsuranceSearchEntry $entry): string => $entry->planName, $entries);
    }

    private function failedInsurers(array $failures): array
    {
        return array_map(fn (InsuranceSearchFailure $failure): int => $failure->insurerId, $failures);
    }

    private function forbidden(): ClientException
    {
        return new ClientException(
            'Client error: `POST /cotizacion` resulted in a `403 Forbidden` response: Token invalido secret-xyz',
            new Request('POST', 'https://insurer.example.com/cotizacion'),
            new Response(403, [], 'Token invalido secret-xyz')
        );
    }

    private function timedOut(): ConnectException
    {
        return new ConnectException(
            'cURL error 28: Operation timed out after 60001 milliseconds secret-xyz',
            new Request('POST', 'https://insurer.example.com/cotizacion')
        );
    }

    public static function universalFailures(): array
    {
        return [
            'forbidden' => [
                fn (self $test): Throwable => $test->forbidden(),
                InsuranceSearchFailureReasonEnum::UNAVAILABLE,
            ],
            'transport timeout' => [
                fn (self $test): Throwable => $test->timedOut(),
                InsuranceSearchFailureReasonEnum::UNAVAILABLE,
            ],
            'validation refusal' => [
                fn (): Throwable => new ValidationException('Vehiculo no asegurable secret-xyz'),
                InsuranceSearchFailureReasonEnum::REJECTED,
            ],
        ];
    }

    public static function humanoFailures(): array
    {
        return [
            'validation refusal' => [
                fn (): Throwable => new ValidationException('Humano: valor fuera de rango secret-xyz'),
                InsuranceSearchFailureReasonEnum::REJECTED,
            ],
            'gateway error' => [
                fn (): Throwable => new ServerException(
                    'Server error: 500 secret-xyz',
                    new Request('POST', 'https://humano.example.com/cotizar'),
                    new Response(500, [], 'secret-xyz')
                ),
                InsuranceSearchFailureReasonEnum::UNAVAILABLE,
            ],
            'transport timeout' => [
                fn (self $test): Throwable => $test->timedOut(),
                InsuranceSearchFailureReasonEnum::UNAVAILABLE,
            ],
        ];
    }

    public function testBothRealAdaptersAreMergedIntoOneListRankedByTheFourRules(): void
    {
        $company = $this->searchCompany();
        $this->bindRealAdapters();
        $model = $this->model();
        $this->seedMarket(
            company: $company,
            model: $model,
            universal: ['A-PA' => ['recommended_score' => 5], 'A-PC' => [], 'A-PT' => []],
            humano: ['mi_auto_premier' => ['sponsored' => true], 'mi_auto_full' => ['commission_rate' => 10]],
        );

        $result = $this->service()->execute($this->input($company, $model));

        $this->assertInstanceOf(HumanoProvider::class, InsuranceProviderFactory::make('humano', app(Apps::class), $company));
        $this->assertInstanceOf(
            UniversalSegurosProvider::class,
            InsuranceProviderFactory::make('universal_seguros', app(Apps::class), $company)
        );
        $this->assertSame([], $result->failures);
        $this->assertSame(
            [
                'humano mi_auto_premier',
                'humano mi_auto_full',
                'universal_seguros A-PA',
                'universal_seguros A-PT',
                'universal_seguros A-PC',
            ],
            $this->planNames($result->entries)
        );
        $this->assertCount(3, $this->universalCalls);
        $this->assertCount(2, $this->humanoCalls);
    }

    public function testEachAdapterMapsItsOwnResponseIntoTheSameQuoteShape(): void
    {
        $company = $this->searchCompany();
        $this->bindRealAdapters();
        $model = $this->model();
        $this->seedMarket(
            company: $company,
            model: $model,
            universal: ['A-PA' => []],
            humano: ['mi_auto_full' => ['commission_rate' => 10]],
        );

        $result = $this->service()->execute($this->input($company, $model));
        $entries = array_column(
            array_map(fn (InsuranceSearchEntry $entry): array => [$entry->provider, $entry], $result->entries),
            1,
            0
        );

        $humano = $entries[HumanoProvider::NAME];
        $universal = $entries[UniversalSegurosProvider::NAME];

        $this->assertSame('H-mi_auto_full', $humano->quote->quoteNumber);
        $this->assertEqualsWithDelta(43332.36, $humano->quote->premium, 0.001);
        $this->assertEqualsWithDelta(6933.18, $humano->quote->tax, 0.001);
        $this->assertEqualsWithDelta(50265.54, $humano->price(), 0.001);
        $this->assertEqualsWithDelta(4333.24, $humano->commission, 0.001);
        $this->assertSame('DOP', $humano->quote->currency);
        $this->assertSame('1', $humano->quote->assumptions['rc_exceso']);

        $this->assertSame('U-A-PA', $universal->quote->quoteNumber);
        $this->assertEqualsWithDelta(5239.66, $universal->quote->premium, 0.01);
        $this->assertEqualsWithDelta(838.34, $universal->quote->tax, 0.01);
        $this->assertEqualsWithDelta(6078.0, $universal->price(), 0.001);
        $this->assertSame('A-PA', $universal->quote->productCode);
        $this->assertEquals(0.0, $universal->commission);

        foreach ([$humano, $universal] as $entry) {
            $this->assertTrue($entry->quote->success);
            $this->assertContains($entry->quote->currency, [null, 'DOP']);
            $this->assertNull($entry->canEmit());
            $this->assertEqualsWithDelta($entry->quote->total, $entry->quote->premium + $entry->quote->tax, 0.02);
        }
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

    #[DataProvider('universalFailures')]
    public function testUniversalFailingKeepsHumanoInTheList(Closure $failure, InsuranceSearchFailureReasonEnum $reason): void
    {
        $company = $this->searchCompany();
        $this->bindRealAdapters();
        $this->universalRespond = fn () => throw $failure($this);
        $model = $this->model();
        $insurers = $this->seedMarket(
            company: $company,
            model: $model,
            universal: ['A-PA' => [], 'A-PC' => []],
            humano: ['mi_auto_full' => []],
        );

        $result = $this->service()->execute($this->input($company, $model));

        $this->assertSame(['humano mi_auto_full'], $this->planNames($result->entries));
        $this->assertCount(1, $result->failures);
        $this->assertSame($insurers['universal']->getId(), $result->failures[0]->insurerId);
        $this->assertSame($reason, $result->failures[0]->reason);
        $this->assertSame($reason->customerMessage(), $result->failures[0]->message);
        $this->assertStringNotContainsString('secret-xyz', $result->failures[0]->message);
    }

    #[DataProvider('humanoFailures')]
    public function testHumanoFailingKeepsUniversalInTheList(Closure $failure, InsuranceSearchFailureReasonEnum $reason): void
    {
        $company = $this->searchCompany();
        $this->bindRealAdapters();
        $this->humanoRespond = fn () => throw $failure($this);
        $model = $this->model();
        $insurers = $this->seedMarket(
            company: $company,
            model: $model,
            universal: ['A-PA' => []],
            humano: ['mi_auto_full' => [], 'mi_auto_premier' => []],
        );

        $result = $this->service()->execute($this->input($company, $model));

        $this->assertSame(['universal_seguros A-PA'], $this->planNames($result->entries));
        $this->assertSame([$insurers['humano']->getId()], $this->failedInsurers($result->failures));
        $this->assertSame($reason, $result->failures[0]->reason);
        $this->assertStringNotContainsString('secret-xyz', $result->failures[0]->message);
    }

    public function testAHumanoBusinessErrorAnsweredWithHttp200IsARejectionNotAQuote(): void
    {
        $company = $this->searchCompany();
        $this->bindRealAdapters();
        $this->humanoRespond = fn (): array => $this->humanoBusinessError();
        $model = $this->model();
        $this->seedMarket(
            company: $company,
            model: $model,
            universal: ['A-PA' => []],
            humano: ['mi_auto_full' => []],
        );

        $result = $this->service()->execute($this->input($company, $model));

        $this->assertSame(['universal_seguros A-PA'], $this->planNames($result->entries));
        $this->assertCount(1, $result->failures);
        $this->assertSame(InsuranceSearchFailureReasonEnum::REJECTED, $result->failures[0]->reason);
        $this->assertStringNotContainsString('VALOR MINIMO', $result->failures[0]->message);
    }

    public function testUniversalAnsweringWithoutAQuoteNumberIsARejection(): void
    {
        $company = $this->searchCompany();
        $this->bindRealAdapters();
        $this->universalRespond = fn (): array => ['data' => ['terminos' => []]];
        $model = $this->model();
        $this->seedMarket(
            company: $company,
            model: $model,
            universal: ['A-PA' => []],
            humano: ['mi_auto_full' => []],
        );

        $result = $this->service()->execute($this->input($company, $model));

        $this->assertSame(['humano mi_auto_full'], $this->planNames($result->entries));
        $this->assertSame(InsuranceSearchFailureReasonEnum::REJECTED, $result->failures[0]->reason);
    }

    public function testWhenBothInsurersFailTheListIsEmptyWithOneFailureEach(): void
    {
        $company = $this->searchCompany();
        $this->bindRealAdapters();
        $this->universalRespond = fn () => throw $this->forbidden();
        $this->humanoRespond = fn () => throw new ValidationException('Humano: rechazado');
        $model = $this->model();
        $insurers = $this->seedMarket(
            company: $company,
            model: $model,
            universal: ['A-PA' => [], 'A-PC' => [], 'A-PT' => []],
            humano: ['mi_auto_full' => [], 'mi_auto_premier' => []],
        );

        $result = $this->service()->execute($this->input($company, $model));

        $this->assertSame([], $result->entries);
        $this->assertEqualsCanonicalizing(
            [$insurers['universal']->getId(), $insurers['humano']->getId()],
            $this->failedInsurers($result->failures)
        );
        $this->assertCount(2, $result->failures);
        $this->assertCount(3, $this->universalCalls);
        $this->assertCount(2, $this->humanoCalls);
    }

    public function testAPartiallyFailedInsurerIsNotReportedWhileAnyOfItsPlansQuoted(): void
    {
        $company = $this->searchCompany();
        $this->bindRealAdapters();
        $this->humanoRespond = fn (string $plan): array => $plan === 'mi_auto_premier'
            ? throw new ValidationException('Humano: rechazado')
            : $this->humanoQuote($plan);
        $model = $this->model();
        $this->seedMarket(
            company: $company,
            model: $model,
            universal: [],
            humano: ['mi_auto_premier' => [], 'mi_auto_full' => []],
        );

        $result = $this->service()->execute($this->input($company, $model));

        $this->assertSame(['humano mi_auto_full'], $this->planNames($result->entries));
        $this->assertSame([], $result->failures);
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

    public function testHumanoRejectingThroughGraphQLNeverLeaksInsurerDetail(): void
    {
        $company = $this->actingCompany();
        $this->bindRealAdapters();
        $this->humanoRespond = fn (): array => $this->humanoBusinessError();
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
        $this->assertSame('universal_seguros A-PA', $data['entries'][0]['plan']['name']);
        $this->assertCount(1, $data['failures']);
        $this->assertSame('REJECTED', $data['failures'][0]['reason']);
        $this->assertSame('This insurer could not quote this vehicle', $data['failures'][0]['message']);

        $body = strtolower((string) json_encode($response->json()));

        foreach (['valor minimo', 'codigoerror', '781,952', 'rc_exceso', 'cdplan', 'montoprima'] as $leak) {
            $this->assertStringNotContainsString($leak, $body);
        }
    }

    public function testAHumanoVehicleWithoutAVersionIsSkippedSilentlyWhileUniversalQuotes(): void
    {
        $company = $this->searchCompany();
        $this->bindRealAdapters();
        $model = $this->model();
        $this->seedMarket(
            company: $company,
            model: $model,
            universal: ['A-PA' => []],
            humano: ['mi_auto_full' => []],
        );

        $result = $this->service()->execute($this->input($company, $model, version: null));

        $this->assertSame(['universal_seguros A-PA'], $this->planNames($result->entries));
        $this->assertSame([], $result->failures);
        $this->assertSame([], $this->humanoCalls);
        $this->assertSame(['A-PA'], $this->universalCalls);
    }

    public function testAnUnmappedHumanoPlanIsSkippedSilentlyWhileTheMappedOneAndUniversalQuote(): void
    {
        $company = $this->searchCompany();
        $this->bindRealAdapters();
        $model = $this->model();
        $this->seedMarket(
            company: $company,
            model: $model,
            universal: ['A-PA' => []],
            humano: ['mi_auto_full' => [], 'mi_auto_premier' => []],
            humanoMapped: ['mi_auto_full'],
        );

        $result = $this->service()->execute($this->input($company, $model));

        $this->assertEqualsCanonicalizing(
            ['humano mi_auto_full', 'universal_seguros A-PA'],
            $this->planNames($result->entries)
        );
        $this->assertSame([], $result->failures);
        $this->assertSame(['mi_auto_full'], $this->humanoCalls);
    }

    public function testAHumanoInsurerWhoseVehicleIsFullyUnmappedNeverShowsUpAsAFailure(): void
    {
        $company = $this->searchCompany();
        $this->bindRealAdapters();
        $model = $this->model();
        $this->seedMarket(
            company: $company,
            model: $model,
            universal: ['A-PA' => []],
            humano: ['mi_auto_full' => []],
            humanoMapped: [],
        );

        $result = $this->service()->execute($this->input($company, $model));

        $this->assertSame(['universal_seguros A-PA'], $this->planNames($result->entries));
        $this->assertSame([], $result->failures);
        $this->assertSame([], $this->humanoCalls);
    }

    public function testIncompleteUnderwritingFailsHumanoAsMissingInformationWhileUniversalStillQuotes(): void
    {
        $company = $this->searchCompany();
        $this->bindRealAdapters();
        $model = $this->model();
        $insurers = $this->seedMarket(
            company: $company,
            model: $model,
            universal: ['A-PA' => []],
            humano: ['mi_auto_full' => []],
        );

        $result = $this->service()->execute($this->input($company, $model, ['ip_address' => '10.0.0.1']));

        $this->assertSame(['universal_seguros A-PA'], $this->planNames($result->entries));
        $this->assertSame([$insurers['humano']->getId()], $this->failedInsurers($result->failures));
        $this->assertSame(InsuranceSearchFailureReasonEnum::INCOMPLETE_INFORMATION, $result->failures[0]->reason);
        $this->assertSame('More information is needed to quote this vehicle', $result->failures[0]->message);
        $this->assertSame([], $this->humanoCalls);
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

    public function testTheClientIpDoesNotBustTheCacheOfEitherAdapter(): void
    {
        $company = $this->searchCompany();
        $this->bindRealAdapters();
        $model = $this->model();
        $this->seedMarket(
            company: $company,
            model: $model,
            universal: ['A-PA' => []],
            humano: ['mi_auto_full' => []],
        );

        $this->service()->execute($this->input($company, $model, $this->underwriting(['ip_address' => '10.0.0.1'])));
        $second = $this->service()->execute($this->input($company, $model, $this->underwriting(['ip_address' => '10.9.9.9'])));

        $this->assertCount(1, $this->universalCalls);
        $this->assertCount(1, $this->humanoCalls);
        $this->assertCount(2, $second->entries);
    }

    public function testAnotherVersionBustsTheCacheOfBothAdapters(): void
    {
        $company = $this->searchCompany();
        $this->bindRealAdapters();
        $model = $this->model();
        $this->seedMarket(
            company: $company,
            model: $model,
            universal: ['A-PA' => []],
            humano: ['mi_auto_full' => []],
            versions: ['LE', 'EX'],
        );

        $this->service()->execute($this->input($company, $model, version: 'LE'));
        $this->service()->execute($this->input($company, $model, version: 'EX'));

        $this->assertCount(2, $this->universalCalls);
        $this->assertCount(2, $this->humanoCalls);
    }

    public function testAnotherUnderwritingFactBustsTheCacheOfBothAdapters(): void
    {
        $company = $this->searchCompany();
        $this->bindRealAdapters();
        $model = $this->model();
        $this->seedMarket(
            company: $company,
            model: $model,
            universal: ['A-PA' => []],
            humano: ['mi_auto_full' => []],
        );

        $this->service()->execute($this->input($company, $model));
        $this->service()->execute($this->input($company, $model, $this->underwriting(['sex' => 'female'])));

        $this->assertCount(2, $this->universalCalls);
        $this->assertCount(2, $this->humanoCalls);
    }

    public function testAnotherVehicleBustsTheCacheOfBothAdapters(): void
    {
        $company = $this->searchCompany();
        $this->bindRealAdapters();
        $first = $this->model();
        $second = $this->model();
        $insurers = $this->seedMarket(
            company: $company,
            model: $first,
            universal: ['A-PA' => []],
            humano: ['mi_auto_full' => []],
        );
        $this->mapUniversal($insurers['universal'], $second);
        $this->mapHumano($insurers['humano'], 'mi_auto_full', $second, 'LE');

        $this->service()->execute($this->input($company, $first));
        $this->service()->execute($this->input($company, $second));

        $this->assertCount(2, $this->universalCalls);
        $this->assertCount(2, $this->humanoCalls);
    }

    public function testAFailureIsNeverCachedSoTheRetryCallsTheInsurerAgainAndOnlyTheSuccessIsReused(): void
    {
        $company = $this->searchCompany();
        $this->bindRealAdapters();
        $this->universalRespond = fn (string $product, int $call): array => $call === 1
            ? throw $this->forbidden()
            : $this->universalQuote($product);
        $model = $this->model();
        $this->seedMarket(
            company: $company,
            model: $model,
            universal: ['A-PA' => []],
            humano: ['mi_auto_full' => []],
        );
        $input = $this->input($company, $model);

        $first = $this->service()->execute($input);
        $second = $this->service()->execute($input);
        $third = $this->service()->execute($input);

        $this->assertCount(1, $first->entries);
        $this->assertCount(1, $first->failures);
        $this->assertCount(2, $second->entries);
        $this->assertSame([], $second->failures);
        $this->assertCount(2, $third->entries);
        $this->assertCount(2, $this->universalCalls);
        $this->assertCount(1, $this->humanoCalls);
    }

    public function testAHumanoBusinessErrorIsNeverCachedEither(): void
    {
        $company = $this->searchCompany();
        $this->bindRealAdapters();
        $this->humanoRespond = fn (string $plan, int $call): array => $call === 1
            ? $this->humanoBusinessError()
            : $this->humanoQuote($plan);
        $model = $this->model();
        $this->seedMarket(
            company: $company,
            model: $model,
            universal: [],
            humano: ['mi_auto_full' => []],
        );
        $input = $this->input($company, $model);

        $first = $this->service()->execute($input);
        $second = $this->service()->execute($input);

        $this->assertSame([], $first->entries);
        $this->assertCount(1, $first->failures);
        $this->assertCount(1, $second->entries);
        $this->assertCount(2, $this->humanoCalls);
    }

    #[DataProvider('chunkSizes')]
    public function testTasksAreDispatchedInChunksOfTheConfiguredWorkerCount(int $workers, array $expectedSizes): void
    {
        config(['insurance.task_workers' => $workers, 'insurance.search_timeout_ms' => 1234]);
        $company = $this->searchCompany();
        $this->bindRealAdapters();
        $model = $this->model();
        $this->seedMarket(
            company: $company,
            model: $model,
            universal: ['A-PA' => [], 'A-PC' => [], 'A-PT' => []],
            humano: ['mi_auto_premier' => [], 'mi_auto_full' => []],
        );
        $runner = new RecordingQuoteTaskRunner();

        $result = $this->service($runner)->execute($this->input($company, $model));

        $this->assertSame($expectedSizes, array_map('count', $runner->batches));
        $this->assertSame(array_fill(0, count($expectedSizes), 1234), $runner->timeouts);
        $this->assertCount(5, $result->entries);
    }

    public static function chunkSizes(): array
    {
        return [
            'two workers' => [2, [2, 2, 1]],
            'three workers' => [3, [3, 2]],
            'more workers than tasks' => [8, [5]],
            'a single worker' => [1, [1, 1, 1, 1, 1]],
        ];
    }

    public function testATimedOutChunkLosesOnlyItsOwnPlansAndKeepsTheOtherChunksQuotes(): void
    {
        config(['insurance.task_workers' => 2]);
        $company = $this->searchCompany();
        $this->bindRealAdapters();
        $model = $this->model();
        $insurers = $this->seedMarket(
            company: $company,
            model: $model,
            universal: ['A-PA' => [], 'A-PC' => []],
            humano: ['mi_auto_premier' => [], 'mi_auto_full' => []],
        );
        $runner = new RecordingQuoteTaskRunner(
            failsBatch: fn (int $index) => $index === 1 ? throw TaskTimeoutException::after(8000) : null
        );

        $result = $this->service($runner)->execute($this->input($company, $model));

        $this->assertCount(2, $runner->batches);
        $this->assertEqualsCanonicalizing(
            ['universal_seguros A-PA', 'universal_seguros A-PC'],
            $this->planNames($result->entries)
        );
        $this->assertSame([$insurers['humano']->getId()], $this->failedInsurers($result->failures));
        $this->assertSame(InsuranceSearchFailureReasonEnum::TIMEOUT, $result->failures[0]->reason);
        $this->assertCount(2, $this->universalCalls);
        $this->assertSame([], $this->humanoCalls);
    }

    public function testATimedOutChunkDoesNotFailAnInsurerThatAlsoQuotedInAnotherChunk(): void
    {
        config(['insurance.task_workers' => 2]);
        $company = $this->searchCompany();
        $this->bindRealAdapters();
        $model = $this->model();
        $this->seedMarket(
            company: $company,
            model: $model,
            universal: ['A-PA' => [], 'A-PC' => [], 'A-PT' => []],
            humano: [],
        );
        $runner = new RecordingQuoteTaskRunner(
            failsBatch: fn (int $index) => $index === 1 ? throw TaskTimeoutException::after(8000) : null
        );

        $result = $this->service($runner)->execute($this->input($company, $model));

        $this->assertCount(2, $result->entries);
        $this->assertSame([], $result->failures);
        $this->assertCount(2, $this->universalCalls);
    }

    public function testTheNumberOfInsurerCallsIsTheNumberOfCandidatePlansAndRepeatsCostNothing(): void
    {
        config(['insurance.task_workers' => 2]);
        $company = $this->searchCompany();
        $this->bindRealAdapters();
        $model = $this->model();
        $this->seedMarket(
            company: $company,
            model: $model,
            universal: ['A-PA' => [], 'A-PC' => [], 'A-PT' => [], 'A-PL' => ['type' => 'law']],
            humano: ['mi_auto_full' => [], 'mi_auto_basico' => []],
            humanoMapped: ['mi_auto_full'],
        );
        $runner = new RecordingQuoteTaskRunner();
        $input = $this->input($company, $model);

        $this->service($runner)->execute($input);

        $dispatched = array_merge(...$runner->batches);
        $this->assertCount(4, $dispatched);
        $this->assertEqualsCanonicalizing(['A-PA', 'A-PC', 'A-PT'], $this->universalCalls);
        $this->assertSame(['mi_auto_full'], $this->humanoCalls);

        $this->service($runner)->execute($input);

        $this->assertCount(4, array_merge(...$runner->batches));
        $this->assertCount(3, $this->universalCalls);
        $this->assertCount(1, $this->humanoCalls);
    }

    public function testAnInsurerWithNoPlanOfTheRequestedCategoryIsNeverCalled(): void
    {
        $company = $this->searchCompany();
        $this->bindRealAdapters();
        $model = $this->model();
        $this->seedMarket(
            company: $company,
            model: $model,
            universal: ['A-PL' => ['type' => 'law']],
            humano: ['mi_auto_full' => []],
        );

        $result = $this->service()->execute($this->input($company, $model));

        $this->assertSame(['humano mi_auto_full'], $this->planNames($result->entries));
        $this->assertSame([], $this->universalCalls);
        $this->assertSame([], $result->failures);
    }

    public function testADispatchErrorOnAChunkIsUnavailableAndSparesTheRest(): void
    {
        config(['insurance.task_workers' => 1]);
        $company = $this->searchCompany();
        $this->bindRealAdapters();
        $model = $this->model();
        $insurers = $this->seedMarket(
            company: $company,
            model: $model,
            universal: ['A-PA' => []],
            humano: ['mi_auto_full' => []],
        );
        $runner = new RecordingQuoteTaskRunner(
            failsBatch: fn (int $index) => $index === 0 ? throw new RuntimeException('serialization exploded') : null
        );

        $result = $this->service($runner)->execute($this->input($company, $model));

        $this->assertSame(['humano mi_auto_full'], $this->planNames($result->entries));
        $this->assertSame([$insurers['universal']->getId()], $this->failedInsurers($result->failures));
        $this->assertSame(InsuranceSearchFailureReasonEnum::UNAVAILABLE, $result->failures[0]->reason);
    }
}
