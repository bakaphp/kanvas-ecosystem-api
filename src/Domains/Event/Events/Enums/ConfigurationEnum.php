<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Enums;

enum ConfigurationEnum: string
{
    case BOOKING_HORIZON_DAYS = 'event_booking_horizon_days';
    case GENERATE_UPCOMING_TIME_SLOTS = 'event_generate_upcoming_time_slots';
}
