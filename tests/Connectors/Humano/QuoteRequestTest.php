<?php

declare(strict_types=1);

namespace Tests\Connectors\Humano;

use Kanvas\Connectors\Humano\DataTransferObject\QuoteRequest;
use Kanvas\Connectors\Humano\Enums\DatoEnum;
use Kanvas\Connectors\Humano\Enums\PlanEnum;
use Kanvas\Exceptions\ValidationException;
use Tests\TestCase;

/**
 * Pure DTO-shape tests — no network, no DB.
 */
class QuoteRequestTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function input(): array
    {
        return [
            'direccion_ip' => '172.24.214.139',
            'fecha_desde' => '2026-09-20',
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

    /**
     * @return array<int, array<string, mixed>>
     */
    private function datosOf(QuoteRequest $request): array
    {
        return $request->toArray()['datos'];
    }

    public function testBuildsOneDatoPerFieldWithHumanosCodesAndLabels(): void
    {
        $datos = $this->datosOf(QuoteRequest::make(PlanEnum::MI_AUTO_FULL, $this->input()));

        $this->assertCount(count(DatoEnum::cases()), $datos);

        $byCode = array_column($datos, null, 'codigoDato');

        $this->assertSame('TOYOTA', $byCode[DatoEnum::MARCA->value]['valorDato']);
        $this->assertSame('Marca Vehículo', $byCode[DatoEnum::MARCA->value]['label']);
        $this->assertSame(1, $byCode[DatoEnum::MARCA->value]['numeroBien']);
    }

    /**
     * Their plan codes are "0".."4". "0" is falsy, so it must never become the
     * product code we stamp on an Order — but it is exactly what has to go out on
     * the wire.
     */
    public function testPlanTravelsAsHumanosNumericCodeNotOurSlug(): void
    {
        $datos = $this->datosOf(QuoteRequest::make(PlanEnum::MI_AUTO_PREMIER, $this->input()));
        $byCode = array_column($datos, null, 'codigoDato');

        $this->assertSame('0', $byCode[DatoEnum::PLAN->value]['valorDato']);
        $this->assertSame('Plan', $byCode[DatoEnum::PLAN->value]['label']);
    }

    public function testDefaultsToAnnualDominicanPesosWhenNotGiven(): void
    {
        $body = QuoteRequest::make(PlanEnum::MI_AUTO_FULL, $this->input())->toArray();

        $this->assertSame(1, $body['codigoMoneda']);
        $this->assertSame('A', $body['codigoVigencia']);
    }

    /**
     * Humano answers a bad quote with a bare message and no field key, so every
     * missing field has to be named locally — and all of them at once, or filling
     * the form costs one round trip per field.
     */
    public function testEveryMissingFieldIsReportedInOneError(): void
    {
        $input = $this->input();
        unset($input['marca'], $input['edad']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('marca, edad');

        QuoteRequest::make(PlanEnum::MI_AUTO_FULL, $input);
    }

    public function testRejectsAValueOutsideTheAllowedSetAndNamesTheSet(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('uso must be one of');

        QuoteRequest::make(PlanEnum::MI_AUTO_FULL, ['uso' => '99'] + $this->input());
    }

    public function testMissingIpIsAValidationErrorRatherThanAnInventedValue(): void
    {
        $input = $this->input();
        unset($input['direccion_ip']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('direccion_ip');

        QuoteRequest::make(PlanEnum::MI_AUTO_FULL, $input);
    }

    /**
     * Optional keys are omitted, never sent as null — the Universal connector lost a
     * day to an explicit null returning a bare 500.
     */
    public function testUnsetReferrerIsOmittedRatherThanSentAsNull(): void
    {
        $body = QuoteRequest::make(PlanEnum::MI_AUTO_FULL, $this->input())->toArray();

        $this->assertArrayNotHasKey('tipoDocumentoReferidor', $body);
        $this->assertArrayNotHasKey('numeroDocumentoReferidor', $body);
    }

    public function testReferrerTypeWithoutItsNumberIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('numero_documento_referidor');

        QuoteRequest::make(PlanEnum::MI_AUTO_FULL, ['tipo_documento_referidor' => 'CED'] + $this->input());
    }
}
