<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalAssistance\Enums;

enum SaleTypeEnum: string
{
    // Original sale type enums
    case ANNUAL = 'Anual';
    case ANNUAL_AUTO_RENEWAL = 'Anual con renovacion automatica';
    case ANNUAL_WITH_RENEWAL = 'Anual con renovación';
    case MONTHLY = 'Mensual';
    case MODULES = 'Módulos';
    case DAILY = 'Por días';
    case WEEKLY = 'Semanal';

    // Travel insurance sale types
    case EMISIVO = 'EMISIVO';
    case RECEPTIVO = 'RECEPTIVO';

    /**
     * Determine sale type based on destination
     */
    public static function getTravelSaleType(string $destination): self
    {
        return match ($destination) {
            'Territorio Nacional' => self::RECEPTIVO,
            default => self::EMISIVO,
        };
    }

    /**
     * Check if the destination requires different country validation
     */
    public function requiresCountryCheck(): bool
    {
        return match ($this) {
            self::EMISIVO => true, // Must be different from Dominicana
            self::RECEPTIVO => false, // Can be Dominicana
            default => false,
        };
    }
}
