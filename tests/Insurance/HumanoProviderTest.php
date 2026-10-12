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
use Kanvas\Insurance\DataTransferObject\CanonicalQuoteInput;
use Kanvas\Insurance\DataTransferObject\CanonicalVehicle;
use Kanvas\Insurance\DataTransferObject\ExternalVehicleReference;
use Kanvas\Insurance\DataTransferObject\InsuranceProduct;
use Kanvas\Insurance\DataTransferObject\InsuranceQuoteRequest;
use Kanvas\Insurance\DataTransferObject\VehicleInsuranceProfile;
use Kanvas\Insurance\Enums\InsuranceCoverageCategoryEnum;
use Kanvas\Insurance\Enums\VehicleFuelTypeEnum;
use Kanvas\Insurance\Services\InsuranceVehicleModelResolver;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class HumanoProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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

    private function canonicalInput(?string $version = 'EX', array $underwriting = []): CanonicalQuoteInput
    {
        return new CanonicalQuoteInput(
            app: Mockery::mock(AppInterface::class),
            company: Mockery::mock(CompanyInterface::class),
            vehicle: new CanonicalVehicle('Honda', 'Civic', 2022, $version),
            profile: new VehicleInsuranceProfile(VehicleFuelTypeEnum::GASOLINE_DIESEL, false, 1500000.0),
            category: InsuranceCoverageCategoryEnum::FULL,
            underwriting: $underwriting ?: [
                'ip_address' => '10.0.0.1',
                'birth_date' => '1993-08-24',
                'marital_status' => 'married',
                'sex' => 'female',
                'usage' => 'private',
                'circulation_zone' => 'north',
            ],
        );
    }

    private function providerWithResolver(MockInterface $service, ?ExternalVehicleReference $reference): HumanoProvider
    {
        $resolver = Mockery::mock(InsuranceVehicleModelResolver::class);
        $resolver->shouldReceive('resolveReference')->andReturn($reference);

        return new HumanoProvider(
            app: Mockery::mock(AppInterface::class),
            company: Mockery::mock(CompanyInterface::class),
            service: $service,
            vehicleModelResolver: $resolver,
        );
    }

    public function testAVehicleIsQuotableOnlyWithBrandAndVersionCodes(): void
    {
        $service = Mockery::mock(HumanoService::class);

        $complete = $this->providerWithResolver($service, new ExternalVehicleReference('40', '2', '2'));
        $noVersion = $this->providerWithResolver($service, new ExternalVehicleReference('40', '2', null));
        $noBrand = $this->providerWithResolver($service, new ExternalVehicleReference(null, '2', '2'));
        $unmapped = $this->providerWithResolver($service, null);

        $this->assertTrue($complete->canQuoteVehicle($this->canonicalInput(), 'mi_auto_full'));
        $this->assertFalse($noVersion->canQuoteVehicle($this->canonicalInput(), 'mi_auto_full'));
        $this->assertFalse($noBrand->canQuoteVehicle($this->canonicalInput(), 'mi_auto_full'));
        $this->assertFalse($unmapped->canQuoteVehicle($this->canonicalInput(), 'mi_auto_full'));
        $this->assertFalse($complete->canQuoteVehicle($this->canonicalInput(), 'not_a_plan'));
    }

    public function testQuoteCanonicalSendsTheMappedHumanoCodes(): void
    {
        $captured = null;
        $service = Mockery::mock(HumanoService::class);
        $service->shouldReceive('quote')->once()->andReturnUsing(function ($request) use (&$captured): array {
            $captured = $request->toArray();

            return ['cotizaciones' => [['numeroCotizacion' => 'H-1', 'montoPrimaProrrata' => 100, 'montoTotalProrrata' => 116]]];
        });
        $provider = $this->providerWithResolver($service, new ExternalVehicleReference('40', '2', '3'));

        $quote = $provider->quoteCanonical($this->canonicalInput(), 'mi_moto_basico', null);

        $datos = array_column($captured['datos'], 'valorDato', 'codigoDato');
        $this->assertSame('H-1', $quote->quoteNumber);
        $this->assertSame('4', $datos[250091]);
        $this->assertSame('40', $datos[250000]);
        $this->assertSame('2', $datos[250001]);
        $this->assertSame('3', $datos[250004]);
        $this->assertSame('24-08-1993', $datos[250069]);
        $this->assertSame('C', $datos[250321]);
        $this->assertSame('F', $datos[250322]);
        $this->assertSame('2', $datos[250323]);
        $this->assertSame('4', $datos[250015]);
        $this->assertSame('1', $datos[250105]);
        $this->assertSame('3000000', $datos[250088]);
        $this->assertSame('10.0.0.1', $captured['direccionIp']);
    }

    public function testQuoteCanonicalWithoutAVersionReferenceNeverReachesTheInsurer(): void
    {
        $service = Mockery::mock(HumanoService::class);
        $service->shouldNotReceive('quote');
        $provider = $this->providerWithResolver($service, new ExternalVehicleReference('40', '2', null));

        $this->expectException(ValidationException::class);

        $provider->quoteCanonical($this->canonicalInput(null), 'mi_auto_full', null);
    }

    public function testQuoteCanonicalNamesTheUnderwritingFactsItIsMissing(): void
    {
        $service = Mockery::mock(HumanoService::class);
        $service->shouldNotReceive('quote');
        $provider = $this->providerWithResolver($service, new ExternalVehicleReference('40', '2', '3'));

        try {
            $provider->quoteCanonical($this->canonicalInput(underwriting: ['ip_address' => '10.0.0.1']), 'mi_auto_full', null);
            $this->fail('Expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('uso', $e->getMessage());
            $this->assertStringContainsString('fecha_nacimiento', $e->getMessage());
            $this->assertStringNotContainsString('valor_vehiculo', $e->getMessage());
        }
    }

    public function testTheVersionCatalogIsServedUnchangedForThePicker(): void
    {
        $versions = ['payload' => [['P_CD_DATO' => '1', 'P_DE_DATO' => 'LX'], ['P_CD_DATO' => '2', 'P_DE_DATO' => 'EX']]];
        $service = Mockery::mock(HumanoService::class);
        $service->shouldReceive('getVersiones')->once()->with('1', '40', '2')->andReturn($versions);
        $provider = $this->provider($service);
        $params = ['plan' => 'mi_auto_full', 'marca' => '40', 'modelo' => '2'];

        $this->assertSame($versions, $provider->getCatalog('vehicle_versions', $params));
        $this->assertSame($versions, $provider->getCatalog('vehicle_versions', $params));
    }

    public function testHumanoCanBeSwitchedOutOfTheSearchByConfig(): void
    {
        $provider = $this->providerWithResolver(
            Mockery::mock(HumanoService::class),
            new ExternalVehicleReference('40', '2', '2')
        );

        config(['insurance.humano.search_enabled' => false]);
        $disabled = $provider->canQuoteVehicle($this->canonicalInput(), 'mi_auto_full');
        config(['insurance.humano.search_enabled' => true]);

        $this->assertFalse($disabled);
        $this->assertTrue($provider->canQuoteVehicle($this->canonicalInput(), 'mi_auto_full'));
    }

    public function testRequiredInformationIsCheckedWithoutCallingTheInsurer(): void
    {
        $service = Mockery::mock(HumanoService::class);
        $service->shouldNotReceive('quote');
        $provider = $this->providerWithResolver($service, new ExternalVehicleReference('40', '2', '2'));

        $this->assertTrue($provider->hasRequiredInformation($this->canonicalInput(), 'mi_auto_full'));
        $this->assertFalse(
            $provider->hasRequiredInformation($this->canonicalInput(underwriting: ['ip_address' => '10.0.0.1']), 'mi_auto_full')
        );
        $this->assertFalse(
            $provider->hasRequiredInformation(
                $this->canonicalInput(underwriting: ['ip_address' => '10.0.0.1', 'usage' => 'bus']),
                'mi_auto_full'
            )
        );
    }

    public function testTheAssumedCoverageRidesInTheQuoteResult(): void
    {
        $service = Mockery::mock(HumanoService::class);
        $service->shouldReceive('quote')->once()->andReturn(['cotizaciones' => [['numeroCotizacion' => 'H-1']]]);
        $provider = $this->providerWithResolver($service, new ExternalVehicleReference('40', '2', '3'));

        $quote = $provider->quoteCanonical($this->canonicalInput(), 'mi_auto_full', null);

        $this->assertSame(
            ['rc_exceso' => '1', 'suma_asegurada_auto_exceso' => '5000000'],
            $quote->assumptions
        );
        $this->assertSame($quote->assumptions, $quote->withoutRaw()->assumptions);
    }

    public function testAnEmptyCatalogAnswerIsNotCached(): void
    {
        $service = Mockery::mock(HumanoService::class);
        $service->shouldReceive('getMarcas')->twice()->with('1')->andReturn(['payload' => []], ['payload' => [['P_CD_DATO' => '40', 'P_DE_DATO' => 'HONDA']]]);
        $provider = $this->provider($service);
        $params = ['plan' => 'mi_auto_full'];

        $first = $provider->getCatalog('vehicle_brands', $params);
        $second = $provider->getCatalog('vehicle_brands', $params);
        $third = $provider->getCatalog('vehicle_brands', $params);

        $this->assertSame([], $first['payload']);
        $this->assertCount(1, $second['payload']);
        $this->assertSame($second, $third);
    }
}
