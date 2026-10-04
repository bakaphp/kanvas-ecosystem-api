<?php

declare(strict_types=1);

namespace Tests\Insurance;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Facades\Cache;
use Kanvas\Connectors\Humano\Enums\PlanEnum;
use Kanvas\Connectors\Humano\Providers\HumanoProvider;
use Kanvas\Connectors\Humano\Services\HumanoService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Insurance\DataTransferObject\InsuranceProduct;
use Kanvas\Insurance\DataTransferObject\InsuranceQuoteRequest;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * The adapter is the only place that knows Humano's field names, so it is tested
 * against a mocked service — no network, no DB.
 */
class HumanoProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Catalog reads go through CatalogCache; an array store keeps each test's
        // hit/miss counting honest without needing Redis.
        config(['cache.default' => 'array']);
        Cache::flush();
    }

    private function provider(MockInterface $service): HumanoProvider
    {
        $app = Mockery::mock(AppInterface::class);
        $app->shouldReceive('getId')->andReturn(1);

        $company = Mockery::mock(CompanyInterface::class);
        $company->shouldReceive('getId')->andReturn(2);

        return new HumanoProvider(
            app: $app,
            company: $company,
            service: $service,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function quotePayload(): array
    {
        return [
            'direccion_ip' => '172.24.214.139',
            'marca' => 'TOYOTA',
            'modelo' => 'COROLLA',
            'version' => 'LE',
            'anio' => '2022',
            'uso' => '4',
            'valor_vehiculo' => '1500000',
            'fecha_nacimiento' => '1990-04-15',
            'edad' => '36',
            'estado_civil' => 'S',
            'sexo' => 'M',
            'zona_circulacion' => '1',
            'rc_exceso' => '1000000',
            'suma_asegurada_auto_exceso' => '500000',
        ];
    }

    public function testNameAndIntegrationIdentifyHumano(): void
    {
        $provider = $this->provider(Mockery::mock(HumanoService::class));

        $this->assertSame('humano', $provider->name());
        $this->assertSame(IntegrationsEnum::HUMANO, $provider->integration());
    }

    /**
     * The trap: on an annual quote Anual and Prorrata are identical, so reading the
     * wrong one passes every annual test and then charges a monthly policy twelve
     * times over. Prorrata is what the chosen vigencia actually costs.
     */
    public function testPricesOffTheProratedAmountsNotTheAnnualOnes(): void
    {
        $service = Mockery::mock(HumanoService::class);
        $service->shouldReceive('quote')->once()->andReturn([
            'codigoError' => null,
            'cotizaciones' => [
                [
                    'numeroCotizacion' => 2256835231,
                    'numeroItem' => 1,
                    'montoPrimaAnual' => 43332.36,
                    'montoComponenteAnual' => 6933.18,
                    'montoTotalAnual' => 50265.54,
                    'montoPrimaProrrata' => 3611.03,
                    'montoComponenteProrrata' => 577.76,
                    'montoTotalProrrata' => 4188.79,
                ],
            ],
        ]);

        $result = $this->provider($service)->quote(new InsuranceQuoteRequest(
            product: PlanEnum::MI_AUTO_FULL->value,
            payload: ['codigo_vigencia' => 'M'] + $this->quotePayload(),
        ));

        $this->assertTrue($result->success);
        $this->assertSame('2256835231', $result->quoteNumber);
        $this->assertSame(3611.03, $result->premium);
        $this->assertSame(577.76, $result->tax);
        $this->assertSame(4188.79, $result->total);
        $this->assertSame('DOP', $result->currency);
    }

    public function testKeepsTheWholeResponseSoPaymentPlansAreNotLost(): void
    {
        $service = Mockery::mock(HumanoService::class);
        $service->shouldReceive('quote')->once()->andReturn([
            'cotizaciones' => [
                [
                    'numeroCotizacion' => 1,
                    'montoTotalProrrata' => 100.0,
                    'planesDePago' => [['codigoPlanPago' => 204, 'descripcionPlanPago' => 'Anual']],
                ],
            ],
        ]);

        $result = $this->provider($service)->quote(new InsuranceQuoteRequest(
            product: PlanEnum::MI_AUTO_FULL->value,
            payload: $this->quotePayload(),
        ));

        $this->assertSame(204, $result->raw['cotizaciones'][0]['planesDePago'][0]['codigoPlanPago']);
    }

    public function testAnUnknownPlanIsRejectedBeforeAnyCallIsMade(): void
    {
        $service = Mockery::mock(HumanoService::class);
        $service->shouldNotReceive('quote');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Unknown Humano plan');

        $this->provider($service)->quote(new InsuranceQuoteRequest(
            product: 'mi_auto_imaginario',
            payload: $this->quotePayload(),
        ));
    }

    /**
     * A caller holding a raw cdPlan from a catalog response shouldn't have to
     * translate it back to our slug.
     */
    public function testTheirNumericPlanCodeIsAcceptedAsWellAsOurSlug(): void
    {
        $service = Mockery::mock(HumanoService::class);
        $service->shouldReceive('getMarcas')->once()->with('4')->andReturn(['marcas' => []]);

        $this->provider($service)->getCatalog('vehicle_brands', ['plan' => '4']);
    }

    public function testProductsCarryReadableCodesAndTheirPlanCodeInMetadata(): void
    {
        $products = $this->provider(Mockery::mock(HumanoService::class))->products();

        $this->assertCount(5, $products);

        $premier = $products[0];
        $this->assertInstanceOf(InsuranceProduct::class, $premier);
        $this->assertSame('mi_auto_premier', $premier->code);
        $this->assertSame('Mi Auto Premier', $premier->name);
        $this->assertSame('0', $premier->metadata['plan_code']);
    }

    public function testAddressCatalogIsFetchedOnceAndThenServedFromCache(): void
    {
        $service = Mockery::mock(HumanoService::class);
        $service->shouldReceive('getProvincias')->once()->andReturn(['provincias' => ['Santo Domingo']]);

        $provider = $this->provider($service);

        $this->assertSame($provider->getCatalog('provinces'), $provider->getCatalog('provinces'));
    }

    /**
     * Humano filters the eligible vehicles per plan, so a key that ignored the plan
     * would serve Mi Moto's catalog to a car quote.
     */
    public function testVehicleCatalogsAreCachedPerPlan(): void
    {
        $service = Mockery::mock(HumanoService::class);
        $service->shouldReceive('getMarcas')->once()->with('1')->andReturn(['marcas' => ['TOYOTA']]);
        $service->shouldReceive('getMarcas')->once()->with('4')->andReturn(['marcas' => ['HONDA']]);

        $provider = $this->provider($service);

        $car = $provider->getCatalog('vehicle_brands', ['plan' => PlanEnum::MI_AUTO_FULL->value]);
        $bike = $provider->getCatalog('vehicle_brands', ['plan' => PlanEnum::MI_MOTO_BASICO->value]);

        $this->assertNotSame($car, $bike);
        $this->assertSame($car, $provider->getCatalog('vehicle_brands', ['plan' => PlanEnum::MI_AUTO_FULL->value]));
    }

    public function testACatalogMissingItsParentIsRejectedRatherThanFetchedEmpty(): void
    {
        $service = Mockery::mock(HumanoService::class);
        $service->shouldNotReceive('getMunicipios');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('requires a provincia');

        $this->provider($service)->getCatalog('municipalities');
    }

    public function testAnUnknownCatalogNamesTheOnesThatExist(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('vehicle_brands');

        $this->provider(Mockery::mock(HumanoService::class))->getCatalog('add_ons');
    }
}
