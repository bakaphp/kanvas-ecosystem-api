<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Metrics\Support;

use Illuminate\Support\Facades\Cache;
use Kanvas\NervousSystem\Dashboard\Enums\DashboardPeriodEnum;

/**
 * Shared cache shape for the Pulse/Dashboard metrics readers — 1h TTL,
 * per-tenant per-period keys, flushed after each rollup so post-cron
 * reads see fresh numbers.
 *
 * Write path: the surface's rollup action calls forget() after each
 * snapshot upsert, so the next read for that tenant sees the
 * freshly-rolled-up day instead of waiting for the TTL.
 *
 * TODAY entries also clear on rollup (cheap, simpler than per-period
 * logic) — at worst this loses up to 1h of today-aggregate caching,
 * which is fine.
 */
abstract class AbstractMetricsCache
{
    public const int TTL_SECONDS = 3600;

    abstract public static function keyPrefix(): string;

    public static function key(int $appsId, int $companiesId, DashboardPeriodEnum $period): string
    {
        return sprintf('%s:%d:%d:%s', static::keyPrefix(), $appsId, $companiesId, $period->value);
    }

    public static function forget(int $appsId, int $companiesId): void
    {
        foreach (DashboardPeriodEnum::cases() as $period) {
            Cache::forget(static::key($appsId, $companiesId, $period));
        }
    }
}
