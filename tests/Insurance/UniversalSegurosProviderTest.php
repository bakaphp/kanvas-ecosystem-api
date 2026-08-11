<?php

declare(strict_types=1);

namespace Tests\Insurance;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use DomainException;
use Illuminate\Support\Facades\Cache;
use Kanvas\Connectors\UniversalSeguros\Enums\ConfigurationEnum;
use Kanvas\Connectors\UniversalSeguros\Enums\CustomFieldEnum;
use Kanvas\Connectors\UniversalSeguros\Enums\DocumentOperationEnum;
use Kanvas\Connectors\UniversalSeguros\Enums\DocumentTransactionEnum;
use Kanvas\Connectors\UniversalSeguros\Enums\ProductEnum;
use Kanvas\Connectors\UniversalSeguros\Providers\UniversalSegurosProvider;
use Kanvas\Connectors\UniversalSeguros\Services\UniversalSegurosService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Insurance\DataTransferObject\InsuranceDocument;
use Kanvas\Insurance\DataTransferObject\InsuranceQuoteRequest;
use Kanvas\Insurance\Enums\InsuranceCustomFieldEnum;
use Kanvas\Insurance\Enums\InsuranceDocumentTypeEnum;
use Kanvas\Insurance\Enums\InsuranceStatusEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * The adapter is the only place that knows Universal's field names, so it is tested
 * against a mocked service — no network, no DB.
 */
class UniversalSegurosProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Catalog reads go through CatalogCache; an array store keeps each test's
        // hit/miss counting honest without needing Redis.
        config(['cache.default' => 'array']);
        Cache::flush();
    }

    private function provider(MockInterface $service, ?string $scopes = null): UniversalSegurosProvider
    {
        $app = Mockery::mock(AppInterface::class);
        $app->shouldReceive('getId')->andReturn(1);

        $company = Mockery::mock(CompanyInterface::class);
        $company->shouldReceive('getId')->andReturn(2);
        // Empty means "not configured", which falls back to the full default set.
        $company->shouldReceive('get')->with(ConfigurationEnum::SCOPES->value)->andReturn($scopes ?? '');

        return new UniversalSegurosProvider(
            app: $app,
            company: $company,
            service: $service,
        );
    }

    private function service(): MockInterface
    {
        return Mockery::mock(UniversalSegurosService::class);
    }

    public function testQuoteMapsUniversalResponseToGenericResult(): void
    {
        $service = $this->service();
        $service->shouldReceive('quote')->once()->andReturn([
            'numeroCotizacion' => 'COT-991',
            'data' => ['terminos' => ['prima' => 1200.5, 'impuesto' => 192.08, 'totalCobro' => 1392.58]],
        ]);

        $result = $this->provider($service)->quote(
            new InsuranceQuoteRequest(product: ProductEnum::PARA_TU_AUTO->value, payload: ['tipo' => 'APA'])
        );

        $this->assertTrue($result->success);
        $this->assertSame('COT-991', $result->quoteNumber);
        $this->assertSame(1200.5, $result->premium);
        $this->assertSame(192.08, $result->tax);
        $this->assertSame(1392.58, $result->total);
    }

    /**
     * Their A-KM sample verbatim. Reading prima first prices it at 0; summing
     * primaFija + primaKm prices it at 1005.85 — totalCobro says 1000.
     */
    public function testPerKilometerProductPricesOffTheFixedPremiumNotTheZeroPrima(): void
    {
        $service = $this->service();
        $service->shouldReceive('quote')->once()->andReturn([
            'numeroCotizacion' => 'COT-KM-1',
            'data' => ['terminos' => [
                'primaFija' => 1000,
                'primaKm' => 5.85,
                'prima' => 0,
                'impuesto' => 0,
                'totalCobro' => 1000,
            ]],
        ]);

        $result = $this->provider($service)->quote(
            new InsuranceQuoteRequest(product: ProductEnum::POR_LO_QUE_CONDUCES->value)
        );

        $this->assertSame(1000.0, $result->premium);
        $this->assertSame(1000.0, $result->total);
    }

    public function testPerKilometerRateIsCarriedSeparatelyFromThePremium(): void
    {
        $service = $this->service();
        $service->shouldReceive('quote')->once()->andReturn([
            'numeroCotizacion' => 'COT-KM-1',
            'data' => ['terminos' => ['primaFija' => 1000, 'primaKm' => 5.85, 'prima' => 0]],
        ]);

        $result = $this->provider($service)->quote(
            new InsuranceQuoteRequest(product: ProductEnum::POR_LO_QUE_CONDUCES->value)
        );

        $this->assertSame(5.85, $result->ratePerKm);
    }

    public function testFixedPremiumProductsCarryNoPerKilometerRate(): void
    {
        $service = $this->service();
        $service->shouldReceive('quote')->once()->andReturn([
            'numeroCotizacion' => 'COT-991',
            'data' => ['terminos' => ['prima' => 1200.5, 'impuesto' => 192.08, 'totalCobro' => 1392.58]],
        ]);

        $result = $this->provider($service)->quote(
            new InsuranceQuoteRequest(product: ProductEnum::PARA_TU_AUTO->value)
        );

        $this->assertNull($result->ratePerKm);
        $this->assertSame(1200.5, $result->premium);
    }

    public function testQuoteWithoutQuoteNumberIsNotSuccessful(): void
    {
        $service = $this->service();
        $service->shouldReceive('quote')->once()->andReturn([]);

        $result = $this->provider($service)->quote(
            new InsuranceQuoteRequest(product: ProductEnum::PARA_TU_AUTO->value)
        );

        $this->assertFalse($result->success);
        $this->assertSame('', $result->quoteNumber);
        $this->assertNull($result->premium);
    }

    public function testQuoteWithUnknownProductIsRejectedBeforeCallingTheInsurer(): void
    {
        $service = $this->service();
        $service->shouldNotReceive('quote');

        $this->expectException(ValidationException::class);

        $this->provider($service)->quote(new InsuranceQuoteRequest(product: 'A-NOPE'));
    }

    public function testIntegrationIsTheUniversalSegurosConnector(): void
    {
        $this->assertSame(
            IntegrationsEnum::UNIVERSAL_SEGUROS,
            $this->provider($this->service())->integration()
        );
    }

    public function testCatalogRoutesToTheMatchingEndpoint(): void
    {
        $service = $this->service();
        $service->shouldReceive('getMunicipios')->once()->with('Santo Domingo')->andReturn(['A', 'B']);

        $result = $this->provider($service)->getCatalog('municipalities', ['provincia' => 'Santo Domingo']);

        $this->assertSame(['A', 'B'], $result);
    }

    /**
     * numeroPagina=-1 hands back the whole catalog, so a second search must not
     * cost a second round trip.
     */
    public function testVehicleModelsAreFetchedOnceAndNarrowedLocally(): void
    {
        $service = $this->service();
        $service->shouldReceive('getVehicleModels')->once()->withNoArgs()->andReturn([
            'data' => [
                ['marca' => 'ABARTH', 'modelos' => [
                    ['idModelo' => 1, 'modelo' => '500'],
                    ['idModelo' => 3, 'modelo' => '124 SPIDER'],
                ]],
                ['marca' => 'ACURA', 'modelos' => [['idModelo' => 9, 'modelo' => 'RDX']]],
            ],
        ]);

        $provider = $this->provider($service);

        $byBrand = $provider->getCatalog('vehicle_models', ['marca' => 'acura']);
        $byModel = $provider->getCatalog('vehicle_models', ['marca' => 'ABARTH', 'modelo' => 'spider']);

        $this->assertSame([['marca' => 'ACURA', 'modelos' => [['idModelo' => 9, 'modelo' => 'RDX']]]], $byBrand['data']);
        $this->assertSame(
            [['marca' => 'ABARTH', 'modelos' => [['idModelo' => 3, 'modelo' => '124 SPIDER']]]],
            $byModel['data']
        );
    }

    public function testVehicleModelsWithoutFiltersReturnTheWholeCatalog(): void
    {
        $catalog = ['data' => [['marca' => 'ABARTH', 'modelos' => [['idModelo' => 1, 'modelo' => '500']]]]];

        $service = $this->service();
        $service->shouldReceive('getVehicleModels')->once()->andReturn($catalog);

        $this->assertSame($catalog, $this->provider($service)->getCatalog('vehicle_models'));
    }

    public function testAddressCatalogsAreCachedPerParameters(): void
    {
        $service = $this->service();
        $service->shouldReceive('getMunicipios')->once()->with('Santiago')->andReturn(['Tamboril']);
        $service->shouldReceive('getMunicipios')->once()->with('Azua')->andReturn(['Padre Las Casas']);

        $provider = $this->provider($service);

        $provider->getCatalog('municipalities', ['provincia' => 'Santiago']);
        $provider->getCatalog('municipalities', ['provincia' => 'Azua']);

        $this->assertSame(['Tamboril'], $provider->getCatalog('municipalities', ['provincia' => 'Santiago']));
    }

    /**
     * They hang off a plan revision that only exists after a quote — per customer.
     */
    public function testRentCarOptionsAreNeverCached(): void
    {
        $service = $this->service();
        $service->shouldReceive('getRentCarOptions')->twice()->andReturn(['codCategoria' => '01']);

        $provider = $this->provider($service);
        $params = ['codProd' => 'A-PA', 'codPlan' => '002', 'revPlan' => '001', 'codRamo' => 'AUTO'];

        $provider->getCatalog('rent_car_options', $params);

        $this->assertSame(['codCategoria' => '01'], $provider->getCatalog('rent_car_options', $params));
    }

    public function testProductsExposeEveryCodeTheInsurerSells(): void
    {
        $products = $this->provider($this->service())->products();

        $this->assertSame(
            ['A-PA', 'A-KM', 'A-PC', 'A-PT', 'A-PL'],
            array_map(fn ($product): string => $product->code, $products)
        );
    }

    public function testOnlySeguroDeLeySkipsInspectionInTheProductList(): void
    {
        $products = $this->provider($this->service())->products();

        $withoutInspection = array_values(array_filter($products, fn ($p): bool => ! $p->requiresInspection));

        $this->assertCount(1, $withoutInspection);
        $this->assertSame(ProductEnum::PARA_TU_SEGURO_DE_LEY->value, $withoutInspection[0]->code);
        $this->assertSame('Para Tu Seguro de Ley', $withoutInspection[0]->name);
    }

    /**
     * The real QA grant: three of five. A line without its emit scope would take
     * the customer's money and then fail at emission.
     */
    public function testOnlyLinesTheAliadoCanEmitComeBackAvailable(): void
    {
        $provider = $this->provider(
            $this->service(),
            'unit.serviceplattform.externos unit.serviceplattform.cotizaciones '
            . 'unit.serviceplattform.polizas unit.serviceplattform.emitir.paratusegurodeley '
            . 'unit.serviceplattform.emitir.paratuauto unit.serviceplattform.emitir.porloqueconduces'
        );

        $available = [];
        foreach ($provider->products() as $product) {
            $available[$product->code] = $product->isAvailable;
        }

        $this->assertSame(
            [
                ProductEnum::PARA_TU_AUTO->value => true,
                ProductEnum::POR_LO_QUE_CONDUCES->value => true,
                ProductEnum::POR_SI_CHOCAS->value => false,
                ProductEnum::POR_SI_PIERDES_TU_AUTO->value => false,
                ProductEnum::PARA_TU_SEGURO_DE_LEY->value => true,
            ],
            $available
        );
    }

    public function testAnUnconfiguredScopeListFallsBackToTheFullGrant(): void
    {
        $products = $this->provider($this->service())->products();

        $this->assertSame([], array_filter($products, fn ($product): bool => ! $product->isAvailable));
    }

    public function testUnknownCatalogNamesTheAvailableOnes(): void
    {
        $provider = $this->provider($this->service());

        try {
            $provider->getCatalog('planets');
            $this->fail('Expected a ValidationException for an unknown catalog');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('provinces', $e->getMessage());
        }
    }

    public function testDocumentsAreUploadedUnderTheInsurersOwnTransactionNames(): void
    {
        $order = Mockery::mock(Order::class);
        $order->shouldReceive('get')
            ->with(InsuranceCustomFieldEnum::QUOTE_NUMBER->value)
            ->andReturn('COT-991');
        $order->shouldReceive('set')
            ->once()
            ->with(InsuranceCustomFieldEnum::STATUS->value, InsuranceStatusEnum::DOCUMENTS_UPLOADED->value);

        $service = $this->service();
        $service->shouldReceive('uploadDocument')
            ->once()
            ->with('COT-991', DocumentTransactionEnum::MATRICULA, DocumentOperationEnum::COTIZACION, '/tmp/m.pdf')
            ->andReturn(['ok' => true]);
        $service->shouldReceive('uploadDocument')
            ->once()
            ->with('COT-991', DocumentTransactionEnum::VIDEO_INSPECCION, DocumentOperationEnum::COTIZACION, '/tmp/v.mp4')
            ->andReturn(['ok' => true]);

        $result = $this->provider($service)->uploadDocuments($order, [
            new InsuranceDocument(InsuranceDocumentTypeEnum::REGISTRATION, '/tmp/m.pdf'),
            new InsuranceDocument(InsuranceDocumentTypeEnum::INSPECTION_VIDEO, '/tmp/v.mp4'),
        ]);

        $this->assertTrue($result->success);
        $this->assertSame(2, $result->uploaded);
    }

    public function testAnUnmappableDocumentTypeIsRefusedRatherThanGuessed(): void
    {
        $order = Mockery::mock(Order::class);
        $order->shouldReceive('get')
            ->with(InsuranceCustomFieldEnum::QUOTE_NUMBER->value)
            ->andReturn('COT-991');

        $this->expectException(DomainException::class);

        $this->provider($this->service())->uploadDocuments($order, [
            new InsuranceDocument(InsuranceDocumentTypeEnum::OTHER, '/tmp/x.pdf'),
        ]);
    }

    public function testOperationsOnAnOrderWithoutAQuoteAreRejected(): void
    {
        $order = Mockery::mock(Order::class);
        $order->shouldReceive('get')
            ->with(InsuranceCustomFieldEnum::QUOTE_NUMBER->value)
            ->andReturn(null);

        $this->expectException(ValidationException::class);

        $this->provider($this->service())->syncPolicy($order);
    }

    public function testSyncStampsThePolicyNumberAndFlipsTheOrderActive(): void
    {
        $order = Mockery::mock(Order::class);
        $order->shouldReceive('get')
            ->with(InsuranceCustomFieldEnum::QUOTE_NUMBER->value)
            ->andReturn('COT-991');
        $order->shouldReceive('get')
            ->with(InsuranceCustomFieldEnum::STATUS->value)
            ->andReturn(InsuranceStatusEnum::AWAITING_PAYMENT->value);
        $order->shouldReceive('set')
            ->once()
            ->with(InsuranceCustomFieldEnum::POLICY_NUMBER->value, 'POL-77');
        $order->shouldReceive('set')
            ->once()
            ->with(InsuranceCustomFieldEnum::STATUS->value, InsuranceStatusEnum::POLICY_ACTIVE->value);

        $service = $this->service();
        $service->shouldReceive('getPolicy')->once()->with('COT-991')->andReturn(['numeroPoliza' => 'POL-77']);

        $result = $this->provider($service)->syncPolicy($order);

        $this->assertTrue($result->success);
        $this->assertSame('POL-77', $result->policyNumber);
        $this->assertSame(InsuranceStatusEnum::POLICY_ACTIVE, $result->status);
    }

    /**
     * The insurer emits out of band, so a missing policy is "not yet", not a failure.
     */
    public function testSyncWithNoPolicyYetKeepsTheCurrentStatus(): void
    {
        $order = Mockery::mock(Order::class);
        $order->shouldReceive('get')
            ->with(InsuranceCustomFieldEnum::QUOTE_NUMBER->value)
            ->andReturn('COT-991');
        $order->shouldReceive('get')
            ->with(InsuranceCustomFieldEnum::STATUS->value)
            ->andReturn(InsuranceStatusEnum::AWAITING_PAYMENT->value);
        $order->shouldNotReceive('set');

        $service = $this->service();
        $service->shouldReceive('getPolicy')->once()->andReturn([]);

        $result = $this->provider($service)->syncPolicy($order);

        $this->assertFalse($result->success);
        $this->assertSame('', $result->policyNumber);
        $this->assertSame(InsuranceStatusEnum::AWAITING_PAYMENT, $result->status);
    }

    public function testInspectionIsRequiredForEveryProductExceptSeguroDeLey(): void
    {
        $provider = $this->provider($this->service());

        $withLey = Mockery::mock(Order::class);
        $withLey->shouldReceive('get')
            ->with(CustomFieldEnum::PRODUCT->value)
            ->andReturn(ProductEnum::PARA_TU_SEGURO_DE_LEY->value);

        $withAuto = Mockery::mock(Order::class);
        $withAuto->shouldReceive('get')
            ->with(CustomFieldEnum::PRODUCT->value)
            ->andReturn(ProductEnum::PARA_TU_AUTO->value);

        $this->assertFalse($provider->requiresInspection($withLey));
        $this->assertTrue($provider->requiresInspection($withAuto));
    }
}
