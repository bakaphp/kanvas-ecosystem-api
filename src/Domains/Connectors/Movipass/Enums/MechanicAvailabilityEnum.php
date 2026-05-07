<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Enums;

enum MechanicAvailabilityEnum: string
{
    case ACTIVO = 'activo';
    case NO_DISPONIBLE = 'no_disponible';
}
