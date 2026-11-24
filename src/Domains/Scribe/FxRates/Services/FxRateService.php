<?php

declare(strict_types=1);

namespace Kanvas\Scribe\FxRates\Services;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Carbon;
use Kanvas\Scribe\FxRates\Models\FxRate;

/**
 * The rate in force on a date: the latest published on or before it. The central bank publishes on business days
 * only, so a Saturday invoice uses Friday's rate; older than MAX_AGE_DAYS means the feed stopped, and no rate is
 * better than a stale one on a fiscal document. An app's own row wins over the platform row (apps_id 0).
 */
class FxRateService
{
    public const int MAX_AGE_DAYS = 7;

    public function rateOn(
        AppInterface $app,
        string $base,
        string $quote,
        Carbon $date
    ): ?FxRate {
        $date = $date->copy()->startOfDay();

        return FxRate::query()
            ->whereIn('apps_id', [$app->getId(), 0])
            ->where('base_currency', strtoupper($base))
            ->where('quote_currency', strtoupper($quote))
            ->whereBetween('rate_date', [$date->copy()->subDays(self::MAX_AGE_DAYS)->toDateString(), $date->toDateString()])
            ->orderByDesc('rate_date')
            ->orderByDesc('apps_id')
            ->first();
    }
}
