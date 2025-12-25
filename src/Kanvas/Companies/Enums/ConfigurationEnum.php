<?php

declare(strict_types=1);

namespace Kanvas\Companies\Enums;

enum ConfigurationEnum: string
{
    case WORKING_HOLIDAY_DAYS = 'working_holiday_days';
    case WORKING_HOURS = 'work_hours';
    case WORKING_DAYS = 'working_days';
    case SPECIAL_DAYS = 'special_days';
    case COUNTRY_CODE = 'country_code';
    case HAVE_FOLLOW_UP = 'have_follow_up';
    case MESSAGE_MINUTES_INTERVAL = 'message_minutes_interval';
    case SPECIAL_DAYS = 'special_days';
    case COUNTRY_CODE = 'country_code';
}
