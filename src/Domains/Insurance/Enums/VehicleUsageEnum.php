<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Enums;

enum VehicleUsageEnum: string
{
    case AMBULANCE = 'ambulance';
    case RENTAL = 'rental';
    case EXHIBITION_PLATE = 'exhibition_plate';
    case PRIVATE = 'private';
    case PUBLIC = 'public';
    case TAXI = 'taxi';
    case STAFF_TRANSPORT = 'staff_transport';
    case SCHOOL_TRANSPORT = 'school_transport';
    case FUNERAL = 'funeral';
    case VALUABLES_TRANSPORT = 'valuables_transport';
    case TOURIST_TRANSPORT = 'tourist_transport';
    case CONTRACTOR = 'contractor';
    case FUEL_TRANSPORT = 'fuel_transport';
    case FLAMMABLE_CARGO = 'flammable_cargo';
    case NON_FLAMMABLE_CARGO = 'non_flammable_cargo';
    case GARBAGE_COLLECTION = 'garbage_collection';
    case DRIVING_SCHOOL = 'driving_school';
}
