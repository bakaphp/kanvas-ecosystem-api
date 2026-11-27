<?php

declare(strict_types=1);

namespace Kanvas\Scribe\FxRates\Services;

use Baka\Contracts\AppInterface;
use Baka\Support\Money;
use Brick\Math\RoundingMode;
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

    /** Rates are stored as DECIMAL(20,10). */
    private const int RATE_SCALE = 10;

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

    /**
     * How many `$to` one `$from` is worth that day, from the published pair or its inverse. Null when neither is
     * published: a caller refuses or reports it, never assumes parity.
     */
    public function conversionRate(
        AppInterface $app,
        string $from,
        string $to,
        Carbon $date
    ): ?float {
        if (strtoupper($from) === strtoupper($to)) {
            return 1.0;
        }

        $direct = $this->rateOn(
            $app,
            $from,
            $to,
            $date
        );

        if ($direct !== null && $direct->rate > 0) {
            return (float) $direct->rate;
        }

        $inverse = $this->rateOn(
            $app,
            $to,
            $from,
            $date
        );

        return $inverse !== null && $inverse->rate > 0
            ? Money::of(1)->dividedBy(Money::of($inverse->rate), self::RATE_SCALE, RoundingMode::HalfUp)->toFloat()
            : null;
    }
}
