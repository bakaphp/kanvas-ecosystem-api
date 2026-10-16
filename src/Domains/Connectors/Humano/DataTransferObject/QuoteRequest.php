<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Humano\DataTransferObject;

use Kanvas\Connectors\Humano\Enums\CurrencyEnum;
use Kanvas\Connectors\Humano\Enums\DatoEnum;
use Kanvas\Connectors\Humano\Enums\DocumentoReferidorEnum;
use Kanvas\Connectors\Humano\Enums\PlanEnum;
use Kanvas\Connectors\Humano\Enums\VigenciaEnum;
use Kanvas\Exceptions\ValidationException;

/**
 * Builds the exact `/cotizacion/productos/cotizar` body from a flat, readable
 * payload.
 *
 * Every dato is validated here rather than at their gateway on purpose: Humano
 * answers a bad quote with `{statusCode, message}` and no field key, so a caller
 * that sends a wrong `uso` learns only that something failed. Failing locally names
 * the field and lists the accepted values.
 *
 * Optional keys are omitted rather than sent as null — the same rule the Universal
 * connector learned the hard way, applied up front here.
 */
class QuoteRequest
{
    /**
     * @param list<Dato> $datos
     */
    public function __construct(
        public readonly PlanEnum $plan,
        public readonly CurrencyEnum $currency,
        public readonly VigenciaEnum $vigencia,
        public readonly string $fechaDesde,
        public readonly string $direccionIp,
        public readonly array $datos,
        public readonly ?DocumentoReferidorEnum $tipoDocumentoReferidor = null,
        public readonly ?string $numeroDocumentoReferidor = null,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function make(PlanEnum $plan, array $payload): self
    {
        $referidorType = self::referidorType($payload['tipo_documento_referidor'] ?? null);
        $referidorNumber = self::trimmed($payload['numero_documento_referidor'] ?? null);

        if ($referidorType !== null && $referidorNumber === null) {
            throw new ValidationException(
                'Humano: numero_documento_referidor is required when tipo_documento_referidor is sent'
            );
        }

        return new self(
            plan: $plan,
            currency: self::currency($payload['codigo_moneda'] ?? null),
            vigencia: self::vigencia($payload['codigo_vigencia'] ?? null),
            fechaDesde: self::trimmed($payload['fecha_desde'] ?? null) ?? date('Y-m-d'),
            direccionIp: self::requiredIp($payload['direccion_ip'] ?? null),
            datos: self::datos($plan, $payload),
            tipoDocumentoReferidor: $referidorType,
            numeroDocumentoReferidor: $referidorType === null ? null : $referidorNumber,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $body = [
            'codigoMoneda' => $this->currency->value,
            'fechaDesde' => $this->fechaDesde,
            'direccionIp' => $this->direccionIp,
            'codigoVigencia' => $this->vigencia->value,
            'datos' => array_map(fn (Dato $dato): array => $dato->toArray(), $this->datos),
        ];

        if ($this->tipoDocumentoReferidor !== null) {
            $body['tipoDocumentoReferidor'] = $this->tipoDocumentoReferidor->value;
            $body['numeroDocumentoReferidor'] = (string) $this->numeroDocumentoReferidor;
        }

        return $body;
    }

    /**
     * Missing datos are reported together — a caller filling a quote form should not
     * have to discover fourteen required fields one round trip at a time.
     *
     * @param array<string, mixed> $payload
     *
     * @return list<Dato>
     */
    protected static function datos(PlanEnum $plan, array $payload): array
    {
        $datos = [new Dato(DatoEnum::PLAN, $plan->code())];
        $missing = [];

        foreach (DatoEnum::fromPayload() as $dato) {
            $value = self::trimmed($payload[$dato->payloadKey()] ?? null);

            if ($value === null) {
                $missing[] = $dato->payloadKey();

                continue;
            }

            self::assertAllowed($dato, $value);
            $datos[] = new Dato($dato, $value);
        }

        if ($missing !== []) {
            throw new ValidationException('Humano quote is missing required fields: ' . implode(', ', $missing));
        }

        return $datos;
    }

    protected static function assertAllowed(DatoEnum $dato, string $value): void
    {
        $allowed = $dato->allowedValues();

        if ($allowed === [] || isset($allowed[$value])) {
            return;
        }

        throw new ValidationException(sprintf(
            'Humano: %s must be one of %s',
            $dato->payloadKey(),
            implode(', ', array_keys($allowed))
        ));
    }

    protected static function currency(mixed $value): CurrencyEnum
    {
        if ($value === null || $value === '') {
            return CurrencyEnum::DOP;
        }

        return CurrencyEnum::tryFrom((int) $value)
            ?? throw new ValidationException('Humano: codigo_moneda must be 1 (DOP), 2 (USD) or 3 (EUR)');
    }

    protected static function vigencia(mixed $value): VigenciaEnum
    {
        if ($value === null || $value === '') {
            return VigenciaEnum::ANUAL;
        }

        return VigenciaEnum::tryFrom((string) $value)
            ?? throw new ValidationException(
                'Humano: codigo_vigencia must be one of ' . implode(
                    ', ',
                    array_column(VigenciaEnum::cases(), 'value')
                )
            );
    }

    protected static function referidorType(mixed $value): ?DocumentoReferidorEnum
    {
        if ($value === null || $value === '') {
            return null;
        }

        return DocumentoReferidorEnum::tryFrom((string) $value)
            ?? throw new ValidationException('Humano: tipo_documento_referidor must be CED, RNC or PAS');
    }

    /**
     * Their gateway rejects a quote with no originating IP, and it is not something
     * this layer can invent — the caller knows whose request it is.
     */
    protected static function requiredIp(mixed $value): string
    {
        return self::trimmed($value)
            ?? throw new ValidationException('Humano quote is missing required fields: direccion_ip');
    }

    protected static function trimmed(mixed $value): ?string
    {
        if ($value === null || is_array($value)) {
            return null;
        }

        $value = trim((string) (is_bool($value) ? (int) $value : $value));

        return $value === '' ? null : $value;
    }
}
