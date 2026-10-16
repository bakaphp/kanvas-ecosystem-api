<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Enums;

use Baka\Support\Str;

enum RoadsideServiceTypeEnum: string
{
    case JUMP_START = 'jump_start';
    case FUEL_DELIVERY = 'fuel_delivery';
    case LIGHT_TOW = 'light_tow';
    case DESIGNATED_DRIVER = 'designated_driver';
    case TIRE_CHANGE = 'tire_change';
    case ACCIDENT_TOW = 'accident_tow';
    case MOTORCYCLE_TOW = 'motorcycle_tow';
    case LOCKSMITH = 'locksmith';

    public function label(): string
    {
        return match ($this) {
            self::JUMP_START => 'Jump start',
            self::FUEL_DELIVERY => 'Fuel delivery',
            self::LIGHT_TOW => 'Light tow',
            self::DESIGNATED_DRIVER => 'Designated driver',
            self::TIRE_CHANGE => 'Tire change',
            self::ACCIDENT_TOW => 'Accident tow',
            self::MOTORCYCLE_TOW => 'Motorcycle tow',
            self::LOCKSMITH => 'Locksmith',
        };
    }

    /**
     * Operators dictate the service over the phone and clients send it as free text, so the same
     * service arrives spelled a dozen ways and in either language. These are input-matching values,
     * not names: they exist so a real request resolves to a case instead of silently skipping the
     * intake questionnaire.
     */
    private function aliases(): array
    {
        return match ($this) {
            self::JUMP_START => ['paso_de_corriente', 'paso_corriente', 'bateria', 'battery'],
            self::FUEL_DELIVERY => ['abasto_de_combustible', 'combustible', 'gasolina', 'fuel'],
            self::LIGHT_TOW => ['grua_liviana', 'grua', 'remolque', 'tow'],
            self::DESIGNATED_DRIVER => ['conductor_designado', 'conductor', 'chofer'],
            self::TIRE_CHANGE => [
                'cambio_de_llanta',
                'cambio_de_neumatico',
                'cambio_neumatico',
                'llanta',
                'neumatico',
            ],
            self::ACCIDENT_TOW => ['grua_por_accidente', 'grua_accidente', 'accidente'],
            self::MOTORCYCLE_TOW => ['grua_para_moto', 'grua_moto', 'moto'],
            self::LOCKSMITH => ['cerrajeria', 'cerrajero', 'llaves'],
        };
    }

    public static function tryFromLabel(?string $value): ?self
    {
        $needle = self::normalize($value);

        if ($needle === '') {
            return null;
        }

        foreach (self::cases() as $case) {
            $haystack = [
                $case->value,
                self::normalize($case->label()),
                ...$case->aliases(),
            ];

            if (in_array($needle, $haystack, true)) {
                return $case;
            }
        }

        return null;
    }

    private static function normalize(?string $value): string
    {
        return str_replace('-', '_', Str::slug(trim((string) $value)));
    }
}
