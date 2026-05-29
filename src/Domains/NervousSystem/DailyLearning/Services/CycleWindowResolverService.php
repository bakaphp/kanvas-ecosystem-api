<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\DailyLearning\Services;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Carbon;
use Kanvas\Companies\Models\Companies;
use Kanvas\Enums\AppEnums;

// One source for daily-learning's "what counts as yesterday" window.
// Anchors the cycle date *in* the tenant tz — never `setTimezone()`-shifts
// into it, which would slide the window backward for west-of-UTC zones.
final class CycleWindowResolverService
{
    /**
     * @return array{cycleLabel: string, timezone: string, dayStart: Carbon, dayEnd: Carbon}
     */
    public static function resolve(AppInterface $app, Companies $company, Carbon $cycleDate): array
    {
        $timezone = self::resolveTimezone($app, $company);
        $cycleLabel = $cycleDate->toDateString();

        return [
            'cycleLabel' => $cycleLabel,
            'timezone' => $timezone,
            // Re-parse from the YMD label so the date is anchored *in* the
            // target tz rather than shifted into it.
            'dayStart' => Carbon::parse($cycleLabel, $timezone)->startOfDay()->utc(),
            'dayEnd' => Carbon::parse($cycleLabel, $timezone)->endOfDay()->utc(),
        ];
    }

    // Company.timezone → app.timezone → AppEnums::DEFAULT_TIMEZONE (NY).
    // Companies.timezone is nullable despite the model's `@property string`
    // docblock (hence the cast); Apps has no column so we read from
    // custom_fields via ->get().
    public static function resolveTimezone(AppInterface $app, Companies $company): string
    {
        /** @psalm-suppress RedundantCastGivenDocblockType */
        $companyTz = (string) $company->timezone;
        if ($companyTz !== '') {
            return $companyTz;
        }

        $appTz = $app->get('timezone');
        if (is_string($appTz) && $appTz !== '') {
            return $appTz;
        }

        return (string) AppEnums::DEFAULT_TIMEZONE->getValue();
    }
}
