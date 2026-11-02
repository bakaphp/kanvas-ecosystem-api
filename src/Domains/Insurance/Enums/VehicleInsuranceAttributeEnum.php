<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Enums;

enum VehicleInsuranceAttributeEnum: string
{
    case FUEL_TYPE = 'combustible';
    case IS_ZERO_KM = 'es_cero_km';
    case ESTIMATED_VALUE = 'valor_estimado';
    case BRAND = 'brand';
    case MODEL = 'model';
    case YEAR = 'year';
    case VERSION = 'version';
    case VIN = 'vin';
    case PLATE = 'plate';
    case COLOR = 'color';
    case ENGINE = 'engine';
    case CYLINDERS = 'cylinders';
    case DOORS = 'doors';
}
