<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Enums;

enum RoadsideAdditionalChargeConceptEnum: string
{
    case FUEL = 'fuel';

    public function label(): string
    {
        return match ($this) {
            self::FUEL => 'Fuel',
        };
    }

    public function appliesTo(?RoadsideServiceTypeEnum $service): bool
    {
        return match ($this) {
            self::FUEL => $service === RoadsideServiceTypeEnum::FUEL_DELIVERY,
        };
    }
}
