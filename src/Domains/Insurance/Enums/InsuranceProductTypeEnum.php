<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Enums;

enum InsuranceProductTypeEnum: string
{
    case VEHICLE = 'vehicle';
    case PLAN = 'insurance-plan';
}
