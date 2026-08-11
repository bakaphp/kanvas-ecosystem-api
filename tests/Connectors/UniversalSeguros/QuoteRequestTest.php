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

    /**
     * Verified against QA: the identical body with `terminos.ceroDeducible: null`
     * returns a bare 500, without the key a clean 400.
     */
    public function testUnsetOptionalsAreOmittedRatherThanSentAsNull(): void
    {
        $payload = QuoteRequest::make(ProductEnum::PARA_TU_AUTO, $this->expressInput())->toArray();

        $this->assertArrayNotHasKey('ceroDeducible', $payload['data']['terminos']);
        $this->assertArrayNotHasKey('rentCar', $payload['data']['terminos']);
        $this->assertArrayNotHasKey('sumaAsegurada', $payload['data']['vehiculo']);
        $this->assertArrayNotHasKey('cliente', $payload['data']);
        $this->assertArrayNotHasKey('endosoCesion', $payload['data']);
        $this->assertArrayNotHasKey('requestId', $payload['data']);
    }

    /**
     * Spatie hands the null straight to `string $cupon = ''`, so PHP TypeErrors
     * before any of our code runs and the FE sees a bare "Internal server error".
     */
    public function testNullsFromTheClientFallBackToTheDefaultInsteadOfCrashing(): void
    {
        $input = $this->expressInput();
        $input['terminos']['cupon'] = null;
        $input['terminos']['fraccionamientoPago'] = null;
        $input['vehiculo']['tipoGas'] = null;
        $input['vehiculo']['tipoInstalacion'] = null;

        $payload = QuoteRequest::make(ProductEnum::PARA_TU_AUTO, $input)->toArray();

        $this->assertSame('', $payload['data']['terminos']['cupon']);
        $this->assertSame('', $payload['data']['vehiculo']['tipoGas']);
        $this->assertSame('', $payload['data']['vehiculo']['tipoInstalacion']);
        $this->assertArrayNotHasKey('fraccionamientoPago', $payload['data']['terminos']);
    }

    public function testAWhollyNullNestedObjectIsTreatedAsAbsent(): void
    {
        $input = $this->expressInput();
        $input['cliente'] = null;
        $input['endosoCesion'] = null;

        $payload = QuoteRequest::make(ProductEnum::PARA_TU_AUTO, $input)->toArray();

        $this->assertArrayNotHasKey('cliente', $payload['data']);
        $this->assertArrayNotHasKey('endosoCesion', $payload['data']);
    }

    /**
     * An empty add-on list is a real answer ("none"), not an unset optional.
     */
    public function testEmptyArraysSurviveTheNullStripping(): void
    {
        $payload = QuoteRequest::make(ProductEnum::PARA_TU_AUTO, $this->expressInput())->toArray();

        $this->assertSame([], $payload['data']['aditamentos']);
    }

    public function testValuesThatAreLegitimatelyFalsyAreKept(): void
    {
        $input = $this->expressInput();
        $input['terminos']['ceroDeducible'] = false;
        $input['vehiculo']['esCeroKm'] = false;

        $payload = QuoteRequest::make(ProductEnum::PARA_TU_AUTO, $input)->toArray();

        $this->assertFalse($payload['data']['terminos']['ceroDeducible']);
        $this->assertFalse($payload['data']['vehiculo']['esCeroKm']);
        $this->assertSame('', $payload['data']['terminos']['cupon']);
    }

    public function testSeguroDeLeyIsTheOnlyProductWithoutInspection(): void
    {
        $this->assertFalse(ProductEnum::PARA_TU_SEGURO_DE_LEY->requiresInspection());
        $this->assertTrue(ProductEnum::PARA_TU_AUTO->requiresInspection());
    }
}
