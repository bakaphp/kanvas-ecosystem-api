<?php

declare(strict_types=1);

namespace Kanvas\Imports\Validations;

use Cron\CronExpression;
use DateTimeZone;
use Kanvas\Exceptions\ValidationException;

class ImportSchedule
{
    public static function assertValid(?string $schedule, ?string $timezone): void
    {
        if ($schedule !== null && ! CronExpression::isValidExpression($schedule)) {
            throw new ValidationException('Invalid schedule "' . $schedule . '". Use a cron expression, e.g. "0 1 * * *".');
        }

        if ($timezone !== null && ! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new ValidationException('Invalid timezone "' . $timezone . '". Use an IANA name, e.g. "America/New_York".');
        }
    }
}
