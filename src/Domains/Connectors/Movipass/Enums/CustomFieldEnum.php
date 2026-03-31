<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Enums;

enum CustomFieldEnum: string
{
    case ROADSIDE_ASSISTANCE_PIN = 'movipass_roadside_assistance_pin';
    case ROADSIDE_ASSISTANCE_PIN_HASH = 'movipass_roadside_assistance_pin_hash';
    case MECHANIC_AVAILABILITY = 'movipass_mechanic_availability';
    case MECHANIC_LOCATION = 'movipass_mechanic_location';
    case MECHANIC_VEHICLE_INFO = 'movipass_mechanic_vehicle_info';
}
