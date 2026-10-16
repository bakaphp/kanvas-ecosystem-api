<?php

declare(strict_types=1);

namespace Tests\Insurance;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\UniversalSeguros\Providers\UniversalSegurosProvider;
use Kanvas\Connectors\UniversalSeguros\Services\UniversalSegurosService;
use Kanvas\Insurance\Actions\BuildVehicleModelCrosswalkAction;
use Kanvas\Insurance\Actions\CreateInsurerAction;
use Kanvas\Insurance\Actions\ListCanonicalVehiclesAction;
use Kanvas\Insurance\Actions\StampVehicleInsuranceProfileAction;
use Kanvas\Insurance\DataTransferObject\CanonicalVehicle;
use Kanvas\Insurance\DataTransferObject\ExternalVehicleModel;
use Kanvas\Insurance\DataTransferObject\InsuranceQuoteRequest;
use Kanvas\Insurance\DataTransferObject\Insurer as InsurerData;
use Kanvas\Insurance\DataTransferObject\VehicleInsuranceProfile;
use Kanvas\Insurance\Enums\VehicleFuelTypeEnum;
use Kanvas\Insurance\Jobs\BuildVehicleModelCrosswalkJob;
use Kanvas\Insurance\Models\InsuranceVehicleModelMap;
use Kanvas\Insurance\Models\Insurer;
use Kanvas\Insurance\Services\InsuranceVehicleModelResolver;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product as ProductDto;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes;
use Kanvas\Inventory\Variants\Models\Variants;
use Mockery;
use Tests\TestCase;

class VehicleModelCrosswalkTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'commerce', 'insurance', 'ecosystem'];

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);
        Cache::flush();
    }

    private function catalog(): array
    {
        return [
            'data' => [
                ['marca' => 'TOYOTA', 'modelos' => [
                    ['idModelo' => 20, 'modelo' => 'COROLLA'],
                    ['idModelo' => 10, 'modelo' => 'Corolla'],
                    ['idModelo' => 30, 'modelo' => 'YARIS'],
                ]],
                ['marca' => 'Citroën', 'modelos' => [['idModelo' => 77, 'modelo' => 'C-Elysée']]],
            ],
        ];
    }

    private function provider(?array $catalog = null): UniversalSegurosProvider
    {
        $service = Mockery::mock(UniversalSegurosService::class);
        $service->shouldReceive('getVehicleModels')->andReturn($catalog ?? $this->catalog());

        return new UniversalSegurosProvider(
            app: app(Apps::class),
            company: auth()->user()->getCurrentCompany(),
            service: $service,
        );
    }

    private function insurer(string $provider = UniversalSegurosProvider::NAME): Insurer
    {
        return new CreateInsurerAction(
            InsurerData::from(
                app(Apps::class),
                auth()->user()->getCurrentCompany(),
                ['name' => 'Universal ' . uniqid(), 'provider' => $provider]
            )
        )->execute();
    }

    private function vehicle(string $brand, string $model, int $year = 2022): Products
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $type = ProductsTypes::firstOrCreate(
            ['slug' => 'vehicle', 'apps_id' => $app->getId(), 'companies_id' => $company->getId()],
            ['name' => 'Vehicle', 'users_id' => $user->getId(), 'weight' => 0]
        );

        return new CreateProductAction(
            new ProductDto(
                app: $app,
                company: $company,
                user: $user,
                name: "{$brand} {$model} " . uniqid(),
                description: 'vehicle',
                productsType: $type,
                sku: 'veh-' . uniqid(),
                attributes: [
                    ['name' => 'brand', 'value' => $brand],
                    ['name' => 'model', 'value' => $model],
                    ['name' => 'year', 'value' => (string) $year],
                ],
            ),
            $user,
        )->setRunWorkflow(false)->execute();
    }

    private function build(Insurer $insurer, array $vehicles, ?array $catalog = null): int
    {
        return new BuildVehicleModelCrosswalkAction($insurer, $this->provider($catalog), $vehicles)->execute();
    }

    private function rows(Insurer $insurer): int
    {
        return InsuranceVehicleModelMap::query()->where('insurers_id', $insurer->getId())->count();
    }

    public function testBuildMapsMatchedModelsAndSkipsUnmatchedOnes(): void
    {
        $insurer = $this->insurer();

        $matched = $this->build($insurer, [
            new CanonicalVehicle('toyota', 'COROLLA'),
            new CanonicalVehicle('Citroen', 'C Elysee'),
            new CanonicalVehicle('Toyota', 'Hilux'),
        ]);

        $this->assertSame(2, $matched);
        $this->assertSame(2, $this->rows($insurer));
        $this->assertSame(
            '10',
            InsuranceVehicleModelMap::query()->where('insurers_id', $insurer->getId())->where('model_key', 'corolla')->value('external_model_id')
        );
    }

    public function testBuildIsIdempotent(): void
    {
        $insurer = $this->insurer();
        $vehicles = [new CanonicalVehicle('Toyota', 'Corolla'), new CanonicalVehicle('Toyota', 'Yaris')];

        $this->build($insurer, $vehicles);
        $first = InsuranceVehicleModelMap::query()->where('insurers_id', $insurer->getId())->orderBy('id')->pluck('external_model_id', 'id')->all();
        $this->build($insurer, $vehicles);
        $second = InsuranceVehicleModelMap::query()->where('insurers_id', $insurer->getId())->orderBy('id')->pluck('external_model_id', 'id')->all();

        $this->assertSame($first, $second);
        $this->assertCount(2, $second);
    }

    public function testRowsForVehiclesTheCatalogNoLongerHasAreDeactivatedNotDuplicated(): void
    {
        $insurer = $this->insurer();
        $this->build($insurer, [new CanonicalVehicle('Toyota', 'Corolla'), new CanonicalVehicle('Toyota', 'Yaris')]);

        Cache::flush();
        $this->build(
            $insurer,
            [new CanonicalVehicle('Toyota', 'Corolla'), new CanonicalVehicle('Toyota', 'Yaris')],
            ['data' => [['marca' => 'TOYOTA', 'modelos' => [['idModelo' => 30, 'modelo' => 'YARIS']]]]]
        );

        $active = InsuranceVehicleModelMap::query()->where('insurers_id', $insurer->getId())->pluck('active', 'model_key')->all();
        $this->assertSame(2, $this->rows($insurer));
        $this->assertFalse((bool) $active['corolla']);
        $this->assertTrue((bool) $active['yaris']);
    }

    public function testAnEmptyCatalogKeepsEveryRowActive(): void
    {
        $insurer = $this->insurer();
        $this->build($insurer, [new CanonicalVehicle('Toyota', 'Corolla')]);

        Cache::flush();
        $this->build($insurer, [new CanonicalVehicle('Toyota', 'Corolla')], ['data' => []]);

        $this->assertTrue((bool) InsuranceVehicleModelMap::query()->where('insurers_id', $insurer->getId())->value('active'));
    }

    public function testManualRowsAreNeverDeactivatedOrOverwrittenByTheBuilder(): void
    {
        $insurer = $this->insurer();
        InsuranceVehicleModelMap::query()->create([
            'apps_id' => $insurer->apps_id,
            'insurers_id' => $insurer->getId(),
            'brand_key' => 'toyota',
            'model_key' => 'corolla',
            'year' => 0,
            'external_model_id' => '999',
            'source' => 'manual',
            'active' => true,
        ]);
        InsuranceVehicleModelMap::query()->create([
            'apps_id' => $insurer->apps_id,
            'insurers_id' => $insurer->getId(),
            'brand_key' => 'toyota',
            'model_key' => 'hilux',
            'year' => 0,
            'external_model_id' => '888',
            'source' => 'manual',
            'active' => true,
        ]);

        $this->build($insurer, [new CanonicalVehicle('Toyota', 'Corolla'), new CanonicalVehicle('Toyota', 'Hilux')]);

        $rows = InsuranceVehicleModelMap::query()->where('insurers_id', $insurer->getId())->pluck('external_model_id', 'model_key')->all();
        $this->assertSame(['corolla' => '999', 'hilux' => '888'], $rows);
        $this->assertSame(2, InsuranceVehicleModelMap::query()->where('insurers_id', $insurer->getId())->where('active', true)->count());
    }

    public function testASoftDeletedRowIsNotResurrectedByTheBuilder(): void
    {
        $insurer = $this->insurer();
        $this->build($insurer, [new CanonicalVehicle('Toyota', 'Corolla')]);
        InsuranceVehicleModelMap::query()->where('insurers_id', $insurer->getId())->update(['is_deleted' => 1]);

        $this->build($insurer, [new CanonicalVehicle('Toyota', 'Corolla')]);

        $this->assertSame(0, InsuranceVehicleModelMap::query()->where('insurers_id', $insurer->getId())->count());
        $this->assertSame(1, InsuranceVehicleModelMap::query()->withoutGlobalScopes()->where('insurers_id', $insurer->getId())->where('is_deleted', 1)->count());
    }

    public function testResolverReturnsTheExternalIdOrNullWhenUnmapped(): void
    {
        $insurer = $this->insurer();
        $this->build($insurer, [new CanonicalVehicle('Toyota', 'Corolla')]);
        $resolver = new InsuranceVehicleModelResolver();
        $app = app(Apps::class);

        $this->assertSame('10', $resolver->resolve($app, UniversalSegurosProvider::NAME, new CanonicalVehicle('TOYOTA', 'corolla', 2021)));
        $this->assertNull($resolver->resolve($app, UniversalSegurosProvider::NAME, new CanonicalVehicle('Toyota', 'Hilux')));
        $this->assertNull($resolver->resolve($app, 'humano', new CanonicalVehicle('Toyota', 'Corolla')));
        $this->assertNull($resolver->resolve($app, UniversalSegurosProvider::NAME, new CanonicalVehicle('', '')));
    }

    public function testResolverIgnoresInactiveRows(): void
    {
        $insurer = $this->insurer();
        $this->build($insurer, [new CanonicalVehicle('Toyota', 'Corolla')]);
        InsuranceVehicleModelMap::query()->where('insurers_id', $insurer->getId())->update(['active' => 0]);

        $this->assertNull(
            new InsuranceVehicleModelResolver()->resolve(app(Apps::class), UniversalSegurosProvider::NAME, new CanonicalVehicle('Toyota', 'Corolla'))
        );
    }

    public function testListCanonicalVehiclesDeduplicatesAndSkipsUnidentifiable(): void
    {
        $this->vehicle('Toyota', 'Corolla', 2020);
        $this->vehicle('TOYOTA', 'corolla', 2021);
        $this->vehicle('Toyota', 'Yaris', 2021);

        $keys = array_map(
            fn (CanonicalVehicle $vehicle): string => $vehicle->brandKey() . '|' . $vehicle->modelKey(),
            new ListCanonicalVehiclesAction(app(Apps::class))->execute()
        );

        $this->assertSame(1, count(array_keys($keys, 'toyota|corolla', true)));
        $this->assertContains('toyota|yaris', $keys);
    }

    public function testJobBuildsTheCrosswalkFromTheProvidersCachedCatalog(): void
    {
        $insurer = $this->insurer();
        $this->vehicle('Toyota', 'Yaris');
        $this->vehicle('Toyota', 'Hilux');
        $provider = $this->provider();
        app()->bind('insurance_provider.' . UniversalSegurosProvider::NAME, fn () => $provider);

        new BuildVehicleModelCrosswalkJob(app(Apps::class), auth()->user()->getCurrentCompany())->handle();

        $rows = InsuranceVehicleModelMap::query()->where('insurers_id', $insurer->getId())->pluck('external_model_id', 'model_key')->all();
        $this->assertSame('30', $rows['yaris']);
        $this->assertArrayNotHasKey('hilux', $rows);
    }

    public function testJobRebindsTheContainerAppBeforeWorking(): void
    {
        $other = Apps::query()->where('id', '!=', app(Apps::class)->getId())->first();

        if ($other === null) {
            $this->markTestSkipped('Needs a second app to prove the rebind.');
        }

        new BuildVehicleModelCrosswalkJob($other, auth()->user()->getCurrentCompany())->handle();

        $this->assertSame($other->getId(), app(Apps::class)->getId());
    }

    public function testUniversalFillsAMissingIdModeloFromTheCrosswalkAndExplicitValuesWin(): void
    {
        $insurer = $this->insurer();
        $this->build($insurer, [new CanonicalVehicle('Toyota', 'Corolla')]);
        $product = $this->vehicle('Toyota', 'Corolla', 2021);
        new StampVehicleInsuranceProfileAction(
            $product,
            new VehicleInsuranceProfile(VehicleFuelTypeEnum::GASOLINE_DIESEL, false, 800000.4),
        )->execute();

        $variant = Mockery::mock(Variants::class)->makePartial();
        $variant->setRelation('product', $product->fresh());

        $captured = [];
        $service = Mockery::mock(UniversalSegurosService::class);
        $service->shouldReceive('quote')->twice()->andReturnUsing(function ($request) use (&$captured) {
            $captured[] = $request->data->vehiculo;

            return ['data' => ['numeroCotizacion' => 'Q-1']];
        });
        $provider = new UniversalSegurosProvider(
            app: app(Apps::class),
            company: auth()->user()->getCurrentCompany(),
            service: $service,
        );

        $provider->quote(new InsuranceQuoteRequest('A-PA', ['vehiculo' => $this->vehiculo()], $variant));
        $provider->quote(new InsuranceQuoteRequest('A-PA', ['vehiculo' => $this->vehiculo() + ['idModelo' => 999, 'combustible' => 'Gas']], $variant));

        $this->assertSame(10, $captured[0]->idModelo);
        $this->assertSame(999, $captured[1]->idModelo);
        $this->assertSame('Gasolina / Diesel', $captured[0]->combustible);
        $this->assertSame('Gas', $captured[1]->combustible);
        $this->assertSame(2021, $captured[0]->anio);
    }

    public function testAnUnmappedVehicleLeavesIdModeloAbsent(): void
    {
        $product = $this->vehicle('Toyota', 'Hilux');
        $variant = Mockery::mock(Variants::class)->makePartial();
        $variant->setRelation('product', $product);
        $provider = new class (
            app(Apps::class),
            auth()->user()->getCurrentCompany(),
            Mockery::mock(UniversalSegurosService::class)
        ) extends UniversalSegurosProvider {
            public function payloadFor(InsuranceQuoteRequest $request): array
            {
                return $this->payloadWithVehicle($request);
            }
        };

        $payload = $provider->payloadFor(new InsuranceQuoteRequest('A-PA', ['vehiculo' => $this->vehiculo()], $variant));

        $this->assertArrayNotHasKey('idModelo', $payload['vehiculo']);
    }

    public function testExplicitIdModeloIsKeptWithOrWithoutAVehicle(): void
    {
        $product = $this->vehicle('Toyota', 'Hilux');
        $variant = Mockery::mock(Variants::class)->makePartial();
        $variant->setRelation('product', $product);

        $captured = [];
        $service = Mockery::mock(UniversalSegurosService::class);
        $service->shouldReceive('quote')->twice()->andReturnUsing(function ($request) use (&$captured) {
            $captured[] = $request->data->vehiculo;

            return ['data' => ['numeroCotizacion' => 'Q-1']];
        });
        $provider = new UniversalSegurosProvider(
            app: app(Apps::class),
            company: auth()->user()->getCurrentCompany(),
            service: $service,
        );
        $payload = ['vehiculo' => $this->vehiculo() + ['idModelo' => 5, 'combustible' => 'Gas']];

        $provider->quote(new InsuranceQuoteRequest('A-PA', $payload));
        $provider->quote(new InsuranceQuoteRequest('A-PA', $payload, $variant));

        $this->assertSame(5, $captured[0]->idModelo);
        $this->assertSame(5, $captured[1]->idModelo);
    }

    public function testUniversalExposesItsCatalogAsExternalVehicleModels(): void
    {
        $entries = $this->provider()->vehicleModelEntries();

        $this->assertCount(4, $entries);
        $this->assertContainsOnlyInstancesOf(ExternalVehicleModel::class, $entries);
        $this->assertSame('Citroën', $entries[3]->brand);
        $this->assertSame('77', $entries[3]->externalId);
    }

    private function vehiculo(): array
    {
        return [
            'anio' => 2021,
            'esCeroKm' => false,
            'cumpleRequisitos' => true,
            'esUsoParticularNoDeportivoNoPublico' => true,
            'valor' => 100,
        ];
    }
}
