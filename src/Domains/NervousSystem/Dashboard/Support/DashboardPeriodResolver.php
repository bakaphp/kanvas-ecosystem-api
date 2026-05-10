<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Dashboard\Support;

use DateTimeZone;
use Illuminate\Support\Carbon;
use Kanvas\NervousSystem\Dashboard\Enums\DashboardPeriodEnum;

/**
 * Period enum → date window plus its prior comparison window.
 * UTC by default; per-tenant timezone can be plumbed through later
 * without changing the call sites.
 */
class DashboardPeriodResolver
{
    public function __construct(
        protected readonly string|DateTimeZone $timezone = 'UTC',
    ) {
    }

    /**
     * @return array{
     *     starts_at: Carbon,
     *     ends_at: Carbon,
     *     prior_starts_at: Carbon,
     *     prior_ends_at: Carbon,
     * }
     */
    public function resolve(DashboardPeriodEnum $period, ?Carbon $now = null): array
    {
        $now = ($now ?? Carbon::now())->copy()->setTimezone($this->timezone);

        return match ($period) {
            DashboardPeriodEnum::TODAY => [
                'starts_at' => $now->copy()->startOfDay(),
                'ends_at' => $now->copy()->endOfDay(),
                'prior_starts_at' => $now->copy()->subDay()->startOfDay(),
                'prior_ends_at' => $now->copy()->subDay()->endOfDay(),
            ],
            DashboardPeriodEnum::YESTERDAY => [
                'starts_at' => $now->copy()->subDay()->startOfDay(),
                'ends_at' => $now->copy()->subDay()->endOfDay(),
                'prior_starts_at' => $now->copy()->subDays(2)->startOfDay(),
                'prior_ends_at' => $now->copy()->subDays(2)->endOfDay(),
            ],
            DashboardPeriodEnum::THIS_WEEK => [
                'starts_at' => $now->copy()->startOfWeek(),
                'ends_at' => $now->copy()->endOfWeek(),
                'prior_starts_at' => $now->copy()->subWeek()->startOfWeek(),
                'prior_ends_at' => $now->copy()->subWeek()->endOfWeek(),
            ],
        };
    }

    /**
     * Iterate every UTC date in [start, end] inclusive. Used by the reader
     * to walk day-by-day deciding snapshot vs live for each one.
     *
     * @return iterable<Carbon>
     */
    public function eachDate(Carbon $start, Carbon $end): iterable
    {
        $cursor = $start->copy()->startOfDay();
        $stop = $end->copy()->startOfDay();

        while ($cursor->lte($stop)) {
            yield $cursor->copy();
            $cursor->addDay();
        }
    }
}
