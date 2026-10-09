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
}
