<?php

declare(strict_types=1);

namespace Kanvas\Analytics\Reporting\Concerns;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Analytics\Reporting\Contracts\RefreshableReportInterface;
use Kanvas\Analytics\Reporting\Jobs\RefreshReportRowsJob;
use Kanvas\Analytics\Reporting\Support\ReportRefreshSuppressor;
use Kanvas\Analytics\Reporting\Support\ReportRegistry;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Throwable;

/**
 * Dispatch a report refresh for whatever a saved model invalidated.
 *
 * Attached to an observer rather than booted from the model: `Model::observe()` cannot be called
 * from a trait's boot method without re-entrant boot, and the models involved already have
 * observers.
 *
 * Every definition registered for the app is asked what this model invalidated, so a connector
 * adding a definition does not also have to touch the observers.
 */
trait RefreshesReportRows
{
    /**
     * Never let reporting break the write that triggered it. A stale report row is recoverable —
     * the nightly rebuild fixes it — whereas a failed save is not.
     */
    protected function dispatchReportRefresh(Model $entity): void
    {
        // A bulk path rebuilds once at the end; dispatching per row would be tens of thousands
        // of jobs, each rebuilding what the next save invalidates.
        if (ReportRefreshSuppressor::isSuppressed()) {
            return;
        }

        try {
            $app = $this->resolveApp($entity);
            $company = $this->resolveCompany($entity);

            if ($app === null || $company === null) {
                return;
            }

            foreach (new ReportRegistry()->for($app) as $definition) {
                if (! $definition instanceof RefreshableReportInterface) {
                    continue;
                }

                foreach ($definition->invalidatedBy() as $modelClass => $resolver) {
                    if (! $entity instanceof $modelClass) {
                        continue;
                    }

                    $ids = $resolver($entity);

                    if ($ids === []) {
                        continue;
                    }

                    RefreshReportRowsJob::dispatch($app, $company, $definition->model(), $ids);
                }
            }
        } catch (Throwable) {
            // Reporting is downstream of everything; it must never be the reason a save fails.
        }
    }

    protected function resolveApp(Model $entity): ?Apps
    {
        $app = $entity->app ?? null;

        if ($app instanceof Apps) {
            return $app;
        }

        return isset($entity->apps_id) ? Apps::getById((int) $entity->apps_id) : null;
    }

    protected function resolveCompany(Model $entity): ?Companies
    {
        $company = $entity->company ?? null;

        if ($company instanceof Companies) {
            return $company;
        }

        return isset($entity->companies_id) && (int) $entity->companies_id > 0
            ? Companies::getById((int) $entity->companies_id)
            : null;
    }
}
