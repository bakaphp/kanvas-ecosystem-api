<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Humano\Enums;

/**
 * Humano quotes through one generic `datos[]` bag keyed by `codigoDato` rather than
 * a typed body, so this table IS the request schema. Nothing outside this connector
 * should ever see a 250xxx code.
 *
 * `allowedValues()` is here because their failure mode is a bare
 * `{statusCode, message}` with no field key — unlike insurers that answer a bad
 * enum with the valid set. Validating locally is the only way a caller finds out
 * which value was wrong.
 */
enum DatoEnum: int
{
    case PLAN = 250091;
    case MARCA = 250000;
    case MODELO = 250001;
    case VERSION = 250004;
    case ANIO = 250003;
    case USO = 250015;
    case VALOR_VEHICULO = 250016;
    case FECHA_NACIMIENTO = 250069;
    case EDAD = 250320;
    case ESTADO_CIVIL = 250321;
    case SEXO = 250322;
    case ZONA_CIRCULACION = 250323;
    case RC_EXCESO = 250105;
    case SUMA_ASEGURADA_AUTO_EXCESO = 250088;

    /**
     * Goes out verbatim as `label` on each dato.
     */
    public function label(): string
    {
        return match ($this) {
            self::PLAN => 'Plan',
            self::MARCA => 'Marca Vehículo',
            self::MODELO => 'Modelo Vehículo',
            self::VERSION => 'Versión',
            self::ANIO => 'Año de Fabricación',
            self::USO => 'Uso del Vehículo',
            self::VALOR_VEHICULO => 'Valor del Vehículo',
            self::FECHA_NACIMIENTO => 'Fecha de Nacimiento',
            self::EDAD => 'Edad del Asegurado',
            self::ESTADO_CIVIL => 'Estado Civil',
            self::SEXO => 'Sexo',
            self::ZONA_CIRCULACION => 'Zona de Circulación',
            self::RC_EXCESO => 'R.C. Exceso',
            self::SUMA_ASEGURADA_AUTO_EXCESO => 'Suma Asegurada Auto Exceso',
        };
    }

    /**
     * The key a caller uses in `InsuranceQuoteRequest::$payload`.
     */
    public function payloadKey(): string
    {
        return match ($this) {
            self::PLAN => 'plan',
            self::MARCA => 'marca',
            self::MODELO => 'modelo',
            self::VERSION => 'version',
            self::ANIO => 'anio',
            self::USO => 'uso',
            self::VALOR_VEHICULO => 'valor_vehiculo',
            self::FECHA_NACIMIENTO => 'fecha_nacimiento',
            self::EDAD => 'edad',
            self::ESTADO_CIVIL => 'estado_civil',
            self::SEXO => 'sexo',
            self::ZONA_CIRCULACION => 'zona_circulacion',
            self::RC_EXCESO => 'rc_exceso',
            self::SUMA_ASEGURADA_AUTO_EXCESO => 'suma_asegurada_auto_exceso',
        };
    }

    /**
     * The datos a caller supplies. PLAN is excluded because it comes from the
     * product the graph was asked to quote, not from the payload — letting it in
     * would give a request two places to disagree about which plan it is.
     *
     * @return list<self>
     */
    public static function fromPayload(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $dato): bool => $dato !== self::PLAN
        ));
    }

    /**
     * Value => label. Empty means free-form (numeric, date or catalog-backed).
     *
     * @return array<string, string>
     */
    public function allowedValues(): array
    {
        return match ($this) {
            self::USO => [
                '1' => 'Ambulancia',
                '2' => 'De Renta (Alquiler)',
                '3' => 'Placa Exhibición',
                '4' => 'Privado',
                '5' => 'Público',
                '6' => 'Taxi',
                '7' => 'Transporte Priv. de Personal',
                '8' => 'Transporte Escolar',
                '9' => 'Vehículo Fúnebre',
                '10' => 'Transporte Dinero y Valores',
                '11' => 'Transporte Turístico',
                '12' => 'Empresa Contratista',
                '13' => 'Transporte de Combustible',
                '14' => 'Transporte Mercancia Inflamable',
                '15' => 'Transporte Mercancia no Inflamable',
                '16' => 'Recogida de Basura',
                '17' => 'Escuela de Aprendizaje',
            ],
            self::ZONA_CIRCULACION => [
                '1' => 'Santo Domingo',
                '2' => 'Zona Norte',
                '3' => 'Zona Este',
                '4' => 'Zona Sur',
            ],
            self::ESTADO_CIVIL => [
                'S' => 'Soltero',
                'C' => 'Casado',
            ],
            self::SEXO => [
                'M' => 'Masculino',
                'F' => 'Femenino',
                'N' => 'No especificado',
            ],
            default => [],
        };
    }
}
