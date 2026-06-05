<?php

declare(strict_types=1);

namespace Kanvas\Apps\Repositories;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Users\Models\UserCompanyApps;

class AppsRepository
{
    /**
     * Get the default company group for this company on the current app.
     */
    public static function findFirstByKey(string $key): ?Apps
    {
        return Apps::where('key', $key)->notDeleted()->firstOrFail();
    }

    /**
     * Get the default company group for this company on the current app.
     */
    public static function getByDomainName(string $domainName): ?Apps
    {
        return Apps::where('domain', $domainName)->notDeleted()->where('domain_based', 1)->first();
    }

    /**
     * Returns a Companies builder scoped to the unique, active companies
     * associated with the given app via `user_company_apps`. Filters out
     * soft-deleted pivot rows AND soft-deleted companies.
     *
     * Avoids the duplicate-row problem of `$app->companies()` HasManyThrough
     * (which returns one row per pivot match, not per unique company).
     */
    public static function getActiveCompaniesForAppBuilder(AppInterface $app): Builder
    {
        return Companies::query()
            ->whereIn('id', UserCompanyApps::query()
                ->where('apps_id', $app->getId())
                ->where('is_deleted', 0)
                ->select('companies_id'))
            ->notDeleted();
    }
}
