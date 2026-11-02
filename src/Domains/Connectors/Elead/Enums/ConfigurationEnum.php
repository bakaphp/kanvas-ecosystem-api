<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Enums;

use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Models\Companies;

enum ConfigurationEnum: string
{
    case ELEAD_API_KEY = 'ELEAD_API_KEY';
    case ELEAD_API_SECRET = 'ELEAD_API_SECRET';
    case ELEAD_DEV_MODE = 'ELEAD_DEV_MODE';
    case COMPANY = 'ELEAD_COMPANY';
    case USER = 'ELEADS_SOLUTION_USER';
    case ELEAD_DAILY_LIMIT = 'ELEAD_DAILY_LIMIT';
    case ELEAD_ALERT_THRESHOLD = 'ELEAD_ALERT_THRESHOLD';
    case ELEAD_CACHE_TTL = 'ELEAD_CACHE_TTL';
    case ELEAD_REF_CACHE_TTL = 'ELEAD_REF_CACHE_TTL';
    case ELEAD_DEBOUNCE_SECONDS = 'ELEAD_DEBOUNCE_SECONDS';
    case ELEAD_SLACK_CHANNEL = 'ELEAD_SLACK_CHANNEL';
    case ELEAD_APPOINTMENT_ACTIVITY_TYPES = 'ELEAD_APPOINTMENT_ACTIVITY_TYPES';
    case ELEAD_APPOINTMENT_DURATION_MINUTES = 'ELEAD_APPOINTMENT_DURATION_MINUTES';

    public static function getUserKey(Companies $company, UserInterface $user): string
    {
        return self::USER->value . '_' . $company->getId();
    }

    /**
     * eLead activity type names (substring match) that count as appointments; a company
     * setting as a comma-separated string or array overrides the default.
     */
    public static function appointmentActivityTypes(Companies $company): array
    {
        $configured = $company->get(self::ELEAD_APPOINTMENT_ACTIVITY_TYPES->value);

        if (is_string($configured)) {
            $configured = explode(',', $configured);
        }

        $names = array_values(array_filter(array_map(
            fn ($name) => Str::trimToNull((string) $name),
            is_array($configured) ? $configured : []
        )));

        return $names === [] ? ['Appointment'] : $names;
    }

    /**
     * eLead activities carry a due date but no end time.
     */
    public static function appointmentDurationMinutes(Companies $company): int
    {
        $minutes = (int) $company->get(self::ELEAD_APPOINTMENT_DURATION_MINUTES->value);

        return $minutes > 0 ? $minutes : 60;
    }
}
