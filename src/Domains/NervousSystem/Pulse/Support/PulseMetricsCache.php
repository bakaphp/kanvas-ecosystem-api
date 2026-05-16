<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Pulse\Support;

use Illuminate\Support\Facades\Cache;
use Kanvas\NervousSystem\Dashboard\Enums\DashboardPeriodEnum;

/**
 * Cache helper for the Pulse metrics resolver. Same shape as
 * DashboardMetricsCache — 1h TTL, per-tenant per-period keys, flushed
 * after each rollup so post-cron reads see fresh numbers.
 */
class PulseMetricsCache
{
    public const int TTL_SECONDS = 3600;

    public static function key(int $appsId, int $companiesId, DashboardPeriodEnum $period): string
    {
        return sprintf('pulse:metrics:%d:%d:%s', $appsId, $companiesId, $period->value);
    }

    public static function forget(int $appsId, int $companiesId): void
    {
        foreach (DashboardPeriodEnum::cases() as $period) {
            Cache::forget(self::key($appsId, $companiesId, $period));
        }
    }
}
