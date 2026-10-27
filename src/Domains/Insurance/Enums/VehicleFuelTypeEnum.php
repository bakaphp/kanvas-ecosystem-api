<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Enums;

enum VehicleFuelTypeEnum: string
{
    case GAS = 'gas';
    case GASOLINE_DIESEL = 'gasoline_diesel';
    case ELECTRIC = 'electric';

    public function universalValue(): string
    {
        return match ($this) {
            self::GAS => 'Gas',
            self::GASOLINE_DIESEL => 'Gasolina / Diesel',
            self::ELECTRIC => 'Vehículo Electrico',
        };
    }
}
