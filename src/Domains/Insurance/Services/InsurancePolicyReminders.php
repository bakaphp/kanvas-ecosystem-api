<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Services;

use Baka\Contracts\AppInterface;
use Kanvas\Insurance\Enums\InsuranceConfigurationEnum;

class InsurancePolicyReminders
{
    public const string TEMPLATE = 'insurance-policy-reminder';

    public const string PUSH_TITLE_TEMPLATE = 'insurance-policy-reminder-push-title';

    public const string PUSH_MESSAGE_TEMPLATE = 'insurance-policy-reminder-push-message';

    public const array DEFAULT_DAYS = [30, 15, 7, 3, 1, 0];

    public const array DEFAULT_CHANNELS = ['mail', 'push', 'database'];

    public static function days(AppInterface $app): array
    {
        return array_values(array_unique(array_map(
            intval(...),
            InsuranceSettings::list($app, InsuranceConfigurationEnum::REMINDER_DAYS, self::DEFAULT_DAYS)
        )));
    }

    public static function channels(AppInterface $app): array
    {
        return InsuranceSettings::list($app, InsuranceConfigurationEnum::REMINDER_CHANNELS, self::DEFAULT_CHANNELS);
    }

    public static function marker(string $endsAt, int $daysLeft): string
    {
        return $endsAt . ':' . $daysLeft;
    }

    public static function sentMarkers(mixed $stored): array
    {
        return array_values(array_filter(explode(',', (string) $stored)));
    }
}
