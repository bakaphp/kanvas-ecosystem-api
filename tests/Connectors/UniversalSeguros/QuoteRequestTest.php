<?php

declare(strict_types=1);

namespace Tests\Connectors\UniversalSeguros;

use Kanvas\Connectors\UniversalSeguros\DataTransferObject\QuoteRequest;
use Kanvas\Connectors\UniversalSeguros\Enums\ProductEnum;
use Tests\TestCase;

class QuoteRequestTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function expressInput(): array
    {
        return [
            'tipo' => 'PAM',
            'vehiculo' => [
                'idModelo' => 1059,
                'anio' => 2025,
                'esCeroKm' => true,
                'combustible' => 'Gasolina / Diesel',
                'cumpleRequisitos' => true,
                'esUsoParticularNoDeportivoNoPublico' => true,
                'valor' => 2000000,
                'inspeccion' => [
                    'tipo' => 'Pre-inspeccionado y Carga de Matrícula',
                    'placa' => 'G320734',
                    'color' => 'BLANCO',
                    'cilindraje' => 4,
                    'puertas' => 4,
                    'chasis' => '1FMCU0GXXDUA25874',
                    'motor' => '25874',
                ],
            ],
            'terminos' => [
                'seguroLey' => 'Auto Exceso',
                'asistenciaVehicular' => false,
                'autoSustituto' => 'Rent-a-Car',
                'fraccionamientoPago' => 'M',
                'formaPago' => 't/c',
            ],
        ];
    }

    public function testBuildsTheDocumentedExpressShape(): void
    {
        $payload = QuoteRequest::make(ProductEnum::PARA_TU_AUTO, $this->expressInput())->toArray();

        $this->assertSame('A-PA', $payload['producto']);
        $this->assertSame('PAM', $payload['data']['tipo']);
        $this->assertSame(1059, $payload['data']['vehiculo']['idModelo']);
        $this->assertSame('1FMCU0GXXDUA25874', $payload['data']['vehiculo']['inspeccion']['chasis']);
        $this->assertSame('Auto Exceso', $payload['data']['terminos']['seguroLey']);
    }

    public function testDefaultsTipoFromProductWhenMissing(): void
    {
        $input = $this->expressInput();
        unset($input['tipo']);

        $payload = QuoteRequest::make(ProductEnum::POR_LO_QUE_CONDUCES, $input)->toArray();

        $this->assertSame('A-KM', $payload['producto']);
        $this->assertSame('AKM', $payload['data']['tipo']);
    }

    public function testClienteIsNullForExpressQuote(): void
    {
        $payload = QuoteRequest::make(ProductEnum::PARA_TU_AUTO, $this->expressInput())->toArray();

        $this->assertNull($payload['data']['cliente']);
        $this->assertNull($payload['data']['endosoCesion']);
    }

    public function testSeguroDeLeyIsTheOnlyProductWithoutInspection(): void
    {
        $this->assertFalse(ProductEnum::PARA_TU_SEGURO_DE_LEY->requiresInspection());
        $this->assertTrue(ProductEnum::PARA_TU_AUTO->requiresInspection());
    }
}
