<?php

declare(strict_types=1);

namespace Kanvas\Companies\Enums;

enum ConfigurationEnum: string
{
    case WORKING_HOLIDAY_DAYS = 'working_holiday_days';
    case WORKING_HOURS = 'work_hours';
    case WORKING_DAYS = 'working_days';
}
