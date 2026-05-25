<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\DailyLearning\Services;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Carbon;
use Kanvas\Companies\Models\Companies;

/**
 * Single source of truth for the daily-learning "what counts as yesterday"
 * window. Resolves the company-vs-app timezone and converts the cycle-date
 * label into a UTC `[dayStart, dayEnd]` pair anchored *in* that timezone
 * — never `setTimezone()`-shifted into it, because that slides the window
 * backward for west-of-UTC zones and was the root cause of the felix-sales
 * smoke-test miss before this PR.
 *
 * Three actions need this window (Enumerate / Summarize / SendDigest); each
 * was carrying its own copy of `resolveTimezone()` + the parse/UTC math.
 * Extracted here so a future tz convention change (e.g. honoring user-level
 * timezones over company) touches one file.
 */
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

    /**
     * Company.timezone → app.timezone → UTC. Companies.timezone is a real
     * column on the model (nullable in practice despite the optimistic
     * `@property string` docblock — see the cast below); Apps has no
     * timezone column so its value comes from custom_fields via ->get().
     */
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

        return 'UTC';
    }
}
