<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Humano\Enums;

enum PlanEnum: string
{
    case MI_AUTO_PREMIER = 'mi_auto_premier';
    case MI_AUTO_FULL = 'mi_auto_full';
    case MI_AUTO_BASICO = 'mi_auto_basico';
    case MI_AUTO_FLEX = 'mi_auto_flex';
    case MI_MOTO_BASICO = 'mi_moto_basico';

    public function code(): string
    {
        return match ($this) {
            self::MI_AUTO_PREMIER => '0',
            self::MI_AUTO_FULL => '1',
            self::MI_AUTO_BASICO => '2',
            self::MI_AUTO_FLEX => '3',
            self::MI_MOTO_BASICO => '4',
        };
    }

    public static function fromCode(string $code): ?self
    {
        foreach (self::cases() as $plan) {
            if ($plan->code() === $code) {
                return $plan;
            }
        }

        return null;
    }

    public function label(): string
    {
        return match ($this) {
            self::MI_AUTO_PREMIER => 'Mi Auto Premier',
            self::MI_AUTO_FULL => 'Mi Auto Full',
            self::MI_AUTO_BASICO => 'Mi Auto Básico',
            self::MI_AUTO_FLEX => 'Mi Auto Flex',
            self::MI_MOTO_BASICO => 'Mi Moto Básico',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::MI_AUTO_PREMIER => 'Todo Riesgo Cero Deducible',
            self::MI_AUTO_FULL => 'Todo Riesgo Con Deducible',
            self::MI_AUTO_BASICO => 'Seguro de Ley',
            self::MI_AUTO_FLEX => 'Todo Riesgo Pérdida Total',
            self::MI_MOTO_BASICO => 'Mi Moto Básico',
        };
    }
}
