<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Enums;

use Kanvas\Apps\Models\Apps;

enum ConfigurationEnum: string
{
    case BOOKING_HORIZON_DAYS = 'event_booking_horizon_days';
    case GENERATE_UPCOMING_TIME_SLOTS = 'event_generate_upcoming_time_slots';
    case SEND_EMAILS = 'event_send_emails';

    /**
     * On unless the app explicitly turns it off: booking apps (TeeTime, the public booking
     * mutations) already rely on these emails, so an unset key must keep sending.
     */
    public static function emailsEnabled(Apps $app): bool
    {
        $configured = $app->get(self::SEND_EMAILS->value);

        return $configured === null || filter_var($configured, FILTER_VALIDATE_BOOL);
    }
}
