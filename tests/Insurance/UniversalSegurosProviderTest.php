<?php

declare(strict_types=1);

namespace Tests\Insurance;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use DomainException;
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
    private function provider(MockInterface $service): UniversalSegurosProvider
    {
        return new UniversalSegurosProvider(
            app: Mockery::mock(AppInterface::class),
            company: Mockery::mock(CompanyInterface::class),
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
     * A-KM prices as primaFija + primaKm instead of a single prima; without the sum
     * the comparator would show that product as having no price at all.
     */
    public function testQuotePremiumForPerKilometerProductSumsBothComponents(): void
    {
        $service = $this->service();
        $service->shouldReceive('quote')->once()->andReturn([
            'numeroCotizacion' => 'COT-KM-1',
            'data' => ['terminos' => ['primaFija' => 800, 'primaKm' => 250.25]],
        ]);

        $result = $this->provider($service)->quote(
            new InsuranceQuoteRequest(product: ProductEnum::POR_LO_QUE_CONDUCES->value)
        );

        $this->assertSame(1050.25, $result->premium);
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
