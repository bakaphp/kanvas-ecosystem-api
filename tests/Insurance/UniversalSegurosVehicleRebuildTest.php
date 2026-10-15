<?php

declare(strict_types=1);

namespace Tests\Insurance;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\UniversalSeguros\DataTransferObject\QuoteRequest;
use Kanvas\Connectors\UniversalSeguros\Enums\ProductEnum;
use Kanvas\Connectors\UniversalSeguros\Providers\UniversalSegurosProvider;
use Kanvas\Connectors\UniversalSeguros\Services\UniversalSegurosService;
use Kanvas\Insurance\DataTransferObject\InsuranceQuoteRequest;
use Kanvas\Insurance\Exceptions\InsuranceValidationException;
use Kanvas\Insurance\Models\InsuranceVehicleModelMap;
use Kanvas\Insurance\Services\VehicleKeyNormalizer;
use Kanvas\Inventory\Variants\Models\Variants;
use Mockery;
use Mockery\MockInterface;
use Tests\Insurance\Concerns\BuildsSearchCatalog;
use Tests\Insurance\Concerns\CreatesVehicleProducts;
use Tests\TestCase;

class UniversalSegurosVehicleRebuildTest extends TestCase
{
    use BuildsSearchCatalog;
    use CreatesVehicleProducts;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'commerce', 'inventory'];

    private const STORED_VEHICLE = [
        'idModelo' => 11,
        'anio' => 2020,
        'esCeroKm' => false,
        'combustible' => 'Gas',
        'cumpleRequisitos' => true,
        'esUsoParticularNoDeportivoNoPublico' => true,
        'valor' => 500000,
    ];

    private array $sent = [];

    private MockInterface $service;

    private string $model;

    protected function setUp(): void
    {
        parent::setUp();

        $this->model = 'Corolla ' . uniqid();
        $this->service = Mockery::mock(UniversalSegurosService::class);
        $this->service->shouldReceive('updateQuote')->andReturnUsing(function (string $number, QuoteRequest $request): array {
            $this->sent[] = $request->toArray()['data'];

            return ['data' => ['numeroCotizacion' => $number]];
        });
    }

    private function provider(): UniversalSegurosProvider
    {
        return new UniversalSegurosProvider(
            app: app(Apps::class),
            company: auth()->user()->getCurrentCompany(),
            service: $this->service,
        );
    }

    private function mapModel(): void
    {
        $insurer = $this->seedInsurer(auth()->user()->getCurrentCompany(), UniversalSegurosProvider::NAME);

        InsuranceVehicleModelMap::query()->create([
            'apps_id' => $insurer->apps_id,
            'insurers_id' => $insurer->getId(),
            'plan_code' => '',
            'brand_key' => 'toyota',
            'model_key' => VehicleKeyNormalizer::normalize($this->model),
            'version_key' => '',
            'year' => 0,
            'external_model_id' => '77',
            'source' => 'manual',
            'active' => true,
        ]);
    }

    private function vehicleVariant(array $extra = []): Variants
    {
        $attributes = array_merge([
            ['name' => 'brand', 'value' => 'Toyota'],
            ['name' => 'model', 'value' => $this->model],
            ['name' => 'year', 'value' => '2022'],
        ], $extra);

        return $this->createVehicleProduct(auth()->user(), $attributes)->variants()->firstOrFail();
    }

    private function request(Variants $vehicle, bool $rebuild): InsuranceQuoteRequest
    {
        return new InsuranceQuoteRequest(
            product: ProductEnum::PARA_TU_AUTO->value,
            payload: ['cliente' => ['nombre' => 'Ana']],
            vehicle: $vehicle,
            rebuildVehicle: $rebuild,
        );
    }

    public function testTheRebuiltVehiculoHasTheVerifiedShape(): void
    {
        $this->mapModel();
        $this->service->shouldNotReceive('getQuote');
        $vehicle = $this->vehicleVariant([
            ['name' => 'combustible', 'value' => 'gasoline_diesel'],
            ['name' => 'valor_estimado', 'value' => '1000000'],
        ]);

        $this->provider()->updateQuote('COT-1', $this->request($vehicle, true));

        $vehiculo = $this->sent[0]['vehiculo'];
        $this->assertSame(77, $vehiculo['idModelo']);
        $this->assertSame(2022, $vehiculo['anio']);
        $this->assertFalse($vehiculo['esCeroKm']);
        $this->assertSame('Gasolina / Diesel', $vehiculo['combustible']);
        $this->assertTrue($vehiculo['cumpleRequisitos']);
        $this->assertTrue($vehiculo['esUsoParticularNoDeportivoNoPublico']);
        $this->assertSame(1000000, $vehiculo['valor']);
        $this->assertSame('Cargar matrícula', $vehiculo['inspeccion']['tipo']);
        $this->assertArrayNotHasKey('sumaAsegurada', $vehiculo);
    }

    public function testALegacyOrderKeepsEchoingTheVehicleStoredInTheInsurersQuote(): void
    {
        $this->mapModel();
        $this->service->shouldReceive('getQuote')->once()->with('COT-1')->andReturn([
            'data' => ['vehiculo' => self::STORED_VEHICLE, 'aditamentos' => []],
        ]);
        $vehicle = $this->vehicleVariant([
            ['name' => 'combustible', 'value' => 'gasoline_diesel'],
            ['name' => 'valor_estimado', 'value' => '1000000'],
        ]);

        $this->provider()->updateQuote('COT-1', $this->request($vehicle, false));

        $this->assertSame(11, $this->sent[0]['vehiculo']['idModelo']);
        $this->assertSame(500000, $this->sent[0]['vehiculo']['valor']);
        $this->assertSame([], $this->sent[0]['aditamentos']);
    }

    public function testAnIncompleteVehicleIsRefusedWithAReasonAboutTheVehicle(): void
    {
        $this->mapModel();
        $this->service->shouldNotReceive('getQuote');
        $vehicle = $this->vehicleVariant();

        try {
            $this->provider()->updateQuote('COT-1', $this->request($vehicle, true));
            $this->fail('expected the vehicle to be refused');
        } catch (InsuranceValidationException $e) {
            $this->assertStringContainsString('fuel type', $e->getMessage());
            $this->assertStringContainsString('estimated value', $e->getMessage());
            $this->assertStringNotContainsString('Universal', $e->getMessage());
        }

        $this->assertSame([], $this->sent);
    }

    public function testAnUnmappedModelIsReportedAsVehicleData(): void
    {
        $vehicle = $this->vehicleVariant([
            ['name' => 'combustible', 'value' => 'gasoline_diesel'],
            ['name' => 'valor_estimado', 'value' => '1000000'],
        ]);

        $this->expectException(InsuranceValidationException::class);
        $this->expectExceptionMessage('vehicle model');

        $this->provider()->updateQuote('COT-1', $this->request($vehicle, true));
    }
}
