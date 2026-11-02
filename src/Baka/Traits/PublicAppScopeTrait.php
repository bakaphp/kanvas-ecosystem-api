<?php

declare(strict_types=1);

namespace Baka\Traits;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Support\LocationContext;

trait PublicAppScopeTrait
{
    /**
     * scopeApp.
     */
    public function scopeFromPublicApp(Builder $query): Builder
    {
        return $query->where('apps_id', '=', 0);
    }

    public function scopeFromCompanyAndGlobal(Builder $query, mixed $company = null): Builder
    {
        $company = $company instanceof Companies
            ? $company
            : LocationContext::companyToScope($this);

        return $query->whereIn('companies_id', [0, $company->getId()]);
    }

    public function scopeFromPublicOrCurrentApp(Builder $query, mixed $app = null): Builder
    {
        $app = $app instanceof Apps ? $app : app(Apps::class);

        return $query->whereIn('apps_id', [0, $app->getId()]);
    }
}
