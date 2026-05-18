<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Dashboard\Support;

use Illuminate\Support\Facades\Cache;
use Kanvas\NervousSystem\Dashboard\Enums\DashboardPeriodEnum;

/**
 * Cache shape + invalidation helpers for the dashboard metrics resolver.
 *
 * Read path: DashboardMetricsService wraps compute() in Cache::remember
 * with 1-hour TTL keyed by (apps_id, companies_id, period).
 *
 * Write path: RollupDashboardMetricsAction calls forget() after each
 * snapshot upsert, so the next dashboard read for that tenant sees
 * the freshly-rolled-up day instead of waiting for the TTL.
 *
 * TODAY entries also clear on rollup (cheap, simpler than per-period
 * logic) — at worst this loses up to 1h of today-aggregate caching,
 * which is fine.
 */
class DashboardMetricsCache
{
    public const int TTL_SECONDS = 3600;

    public static function key(int $appsId, int $companiesId, DashboardPeriodEnum $period): string
    {
        return sprintf('dashboard:metrics:%d:%d:%s', $appsId, $companiesId, $period->value);
    }

    /**
     * Drop all cached metric responses for a single tenant. Called from
     * the rollup action so the dashboard sees fresh numbers right after
     * the cron writes a new snapshot.
     */
    public static function forget(int $appsId, int $companiesId): void
    {
        foreach (DashboardPeriodEnum::cases() as $period) {
            Cache::forget(self::key($appsId, $companiesId, $period));
        }
    }
}
