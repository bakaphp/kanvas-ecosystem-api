<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Metrics\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Kanvas\NervousSystem\Dashboard\Enums\DashboardPeriodEnum;
use Kanvas\NervousSystem\Dashboard\Support\DashboardPeriodResolver;
use Kanvas\NervousSystem\Metrics\Support\AbstractMetricsCache;
use Kanvas\NervousSystem\Models\ImmutableBaseModel;
use Spatie\LaravelData\Data;

/**
 * Shared reader shape for the Pulse/Dashboard KPI surfaces. Walks each
 * day in the requested window: snapshot for a past day, live aggregate
 * for today, fallback to live if a past-day snapshot is missing (cron
 * failure shouldn't blank the surface).
 *
 * Period semantics reuse DashboardPeriodResolver — TODAY, YESTERDAY,
 * THIS_WEEK + their prior windows for delta arrows — even from the
 * Pulse surface; there's only one period vocabulary today.
 *
 * Cached per (app, company, period) via the surface's own cache class;
 * the rollup action busts the tenant's cache after each snapshot write.
 */
abstract class AbstractMetricsReaderService
{
    public function __construct(
        protected readonly DashboardPeriodResolver $periodResolver = new DashboardPeriodResolver(),
    ) {
    }

    public function compute(
        AppInterface $app,
        CompanyInterface $company,
        DashboardPeriodEnum $period,
    ): Data {
        $cacheClass = $this->cacheClass();
        $cacheKey = $cacheClass::key($app->getId(), $company->getId(), $period);

        return Cache::remember(
            $cacheKey,
            $cacheClass::TTL_SECONDS,
            fn (): Data => $this->computeFresh($app, $company, $period),
        );
    }

    abstract protected function computeFresh(
        AppInterface $app,
        CompanyInterface $company,
        DashboardPeriodEnum $period,
    ): Data;

    /** @return class-string<AbstractMetricsCache> */
    abstract protected function cacheClass(): string;

    protected function sumWindow(
        AppInterface $app,
        CompanyInterface $company,
        Carbon $start,
        Carbon $end,
    ): object {
        $today = Carbon::now()->startOfDay();
        $total = $this->newAggregateRow();

        foreach ($this->periodResolver->eachDate($start, $end) as $day) {
            if ($day->lt($today)) {
                $total->addRow($this->readPastDay($app, $company, $day));
            } else {
                $total->addRow($this->aggregateLive(
                    $app,
                    $company,
                    $day->copy()->startOfDay(),
                    $day->copy()->endOfDay(),
                ));
            }
        }

        return $total;
    }

    abstract protected function newAggregateRow(): object;

    abstract protected function aggregateLive(
        AppInterface $app,
        CompanyInterface $company,
        Carbon $start,
        Carbon $end,
    ): object;

    /** @return class-string<ImmutableBaseModel> */
    abstract protected function modelClass(): string;

    abstract protected function fromSnapshot(Model $snapshot): object;

    private function readPastDay(
        AppInterface $app,
        CompanyInterface $company,
        Carbon $day,
    ): object {
        $modelClass = $this->modelClass();

        $snapshot = $modelClass::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->whereDate('metric_date', $day->toDateString())
            ->first();

        if ($snapshot !== null) {
            return $this->fromSnapshot($snapshot);
        }

        return $this->aggregateLive(
            $app,
            $company,
            $day->copy()->startOfDay(),
            $day->copy()->endOfDay(),
        );
    }
}
