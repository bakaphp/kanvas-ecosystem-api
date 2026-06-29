<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalSeguros\Enums;

enum ProductEnum: string
{
    case PARA_TU_AUTO = 'A-PA';
    case POR_LO_QUE_CONDUCES = 'A-KM';
    case POR_SI_CHOCAS = 'A-PC';
    case POR_SI_PIERDES_TU_AUTO = 'A-PT';
    case PARA_TU_SEGURO_DE_LEY = 'A-PL';

    public function defaultTipo(): string
    {
        return match ($this) {
            self::PARA_TU_AUTO => 'APA',
            self::POR_LO_QUE_CONDUCES => 'AKM',
            self::POR_SI_CHOCAS => 'APC',
            self::POR_SI_PIERDES_TU_AUTO => 'APT',
            self::PARA_TU_SEGURO_DE_LEY => 'APL',
        };
    }

    public function expressTipo(): ?string
    {
        return match ($this) {
            self::PARA_TU_AUTO => 'PAM',
            default => null,
        };
    }

    // A-PL (Seguro de Ley) is the only product that skips inspection.
    public function requiresInspection(): bool
    {
        return $this !== self::PARA_TU_SEGURO_DE_LEY;
    }

    public function emitScope(): string
    {
        return match ($this) {
            self::PARA_TU_AUTO => 'unit.serviceplattform.emitir.paratuauto',
            self::POR_LO_QUE_CONDUCES => 'unit.serviceplattform.emitir.porloqueconduces',
            self::POR_SI_CHOCAS => 'unit.serviceplattform.emitir.porsichocas',
            self::POR_SI_PIERDES_TU_AUTO => 'unit.serviceplattform.emitir.porsipierdestuauto',
            self::PARA_TU_SEGURO_DE_LEY => 'unit.serviceplattform.emitir.paratusegurodeley',
        };
    }
}
