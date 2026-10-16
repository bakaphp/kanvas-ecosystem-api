<?php

declare(strict_types=1);

namespace Tests\Connectors\UniversalSeguros;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\UniversalSeguros\DataTransferObject\QuoteRequest;
use Kanvas\Connectors\UniversalSeguros\Providers\UniversalSegurosProvider;
use Kanvas\Connectors\UniversalSeguros\Services\UniversalSegurosService;
use Kanvas\Insurance\DataTransferObject\InsuranceQuoteRequest;
use Mockery;
use Tests\TestCase;

final class DefaultTerminosTest extends TestCase
{
    private const VEHICULO = [
        'idModelo' => 1059,
        'anio' => 2025,
        'esCeroKm' => true,
        'combustible' => 'Gasolina / Diesel',
        'cumpleRequisitos' => true,
        'esUsoParticularNoDeportivoNoPublico' => true,
        'valor' => 2000000,
    ];

    private const QUOTE = ['numeroCotizacion' => 'Q-1', 'data' => ['terminos' => ['prima' => 100, 'impuesto' => 18, 'totalCobro' => 118]]];

    private function provider(array &$sent): UniversalSegurosProvider
    {
        $service = Mockery::mock(UniversalSegurosService::class);
        $service->shouldReceive('quote')->andReturnUsing(function (QuoteRequest $request) use (&$sent) {
            $sent = $request->toArray();

            return self::QUOTE;
        });
        $service->shouldReceive('updateQuote')->andReturnUsing(function (string $number, QuoteRequest $request) use (&$sent) {
            $sent = $request->toArray();

            return self::QUOTE;
        });

        return new UniversalSegurosProvider(
            app: app(Apps::class),
            company: auth()->user()->getCurrentCompany(),
            service: $service,
        );
    }

    public function testASearchQuoteWithoutTermsSendsTheDefaults(): void
    {
        $sent = [];
        $this->provider($sent)->quote(new InsuranceQuoteRequest(product: 'A-PA', payload: ['vehiculo' => self::VEHICULO]));

        $this->assertSame('Auto Exceso', $sent['data']['terminos']['seguroLey']);
        $this->assertSame('No', $sent['data']['terminos']['autoSustituto']);
        $this->assertSame('M', $sent['data']['terminos']['fraccionamientoPago']);
        $this->assertSame('t/c', $sent['data']['terminos']['formaPago']);
        $this->assertFalse($sent['data']['terminos']['asistenciaVehicular']);
    }

    public function testTermsSuppliedByTheCallerWinOverTheDefaults(): void
    {
        $sent = [];
        $this->provider($sent)->quote(new InsuranceQuoteRequest(
            product: 'A-PA',
            payload: ['vehiculo' => self::VEHICULO, 'terminos' => ['seguroLey' => 'Plus', 'autoSustituto' => 'Rent-a-Car']]
        ));

        $this->assertSame('Plus', $sent['data']['terminos']['seguroLey']);
        $this->assertSame('Rent-a-Car', $sent['data']['terminos']['autoSustituto']);
        $this->assertSame('M', $sent['data']['terminos']['fraccionamientoPago']);
    }

    public function testTheContractUpdateAlsoCarriesTheDefaults(): void
    {
        $sent = [];
        $this->provider($sent)->updateQuote('Q-1', new InsuranceQuoteRequest(
            product: 'A-PA',
            payload: ['vehiculo' => self::VEHICULO, 'cliente' => ['nombre' => 'Ana']]
        ));

        $this->assertSame('Auto Exceso', $sent['data']['terminos']['seguroLey']);
        $this->assertSame('t/c', $sent['data']['terminos']['formaPago']);
    }

    public function testAFreshQuoteCopiesTheSearchedOneAndCarriesTheRequestId(): void
    {
        $sent = [];
        $service = Mockery::mock(UniversalSegurosService::class);
        $service->shouldReceive('getQuote')->with('Q-OLD')->andReturn([
            'numeroCotizacion' => 'Q-OLD',
            'data' => [
                'producto' => 'A-PA',
                'requestId' => null,
                'vehiculo' => self::VEHICULO,
                'terminos' => ['seguroLey' => 'Plus', 'asistenciaVehicular' => false, 'autoSustituto' => 'No', 'prima' => 100],
                'cliente' => ['nombre' => 'Someone else'],
            ],
        ]);
        $service->shouldReceive('quote')->andReturnUsing(function (QuoteRequest $request) use (&$sent) {
            $sent = $request->toArray();

            return ['numeroCotizacion' => 'Q-NEW', 'data' => ['producto' => 'A-PA', 'terminos' => ['prima' => 100, 'impuesto' => 18, 'totalCobro' => 118]]];
        });
        $provider = new UniversalSegurosProvider(
            app: app(Apps::class),
            company: auth()->user()->getCurrentCompany(),
            service: $service,
        );

        $fresh = $provider->freshQuote('Q-OLD', 'A-PA', 'insurance-order-9-abc12345');

        $this->assertSame('Q-NEW', $fresh->quoteNumber);
        $this->assertSame('insurance-order-9-abc12345', $sent['data']['requestId']);
        $this->assertSame(1059, $sent['data']['vehiculo']['idModelo']);
        $this->assertSame('Plus', $sent['data']['terminos']['seguroLey']);
        $this->assertArrayNotHasKey('cliente', $sent['data']);
    }
}
