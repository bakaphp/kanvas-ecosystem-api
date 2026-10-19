<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Services;

class InsurancePolicyReminders
{
    public const string TEMPLATE = 'insurance-policy-reminder';

    public const string PUSH_TITLE_TEMPLATE = 'insurance-policy-reminder-push-title';

    public const string PUSH_MESSAGE_TEMPLATE = 'insurance-policy-reminder-push-message';

    public static function days(): array
    {
        return array_values(array_unique(array_map(intval(...), (array) config('insurance.reminders.days'))));
    }

    public static function channels(): array
    {
        return array_values((array) config('insurance.reminders.channels'));
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
