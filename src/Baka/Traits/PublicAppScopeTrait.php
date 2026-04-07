<?php

declare(strict_types=1);

namespace Baka\Traits;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Companies\Models\Companies;

trait PublicAppScopeTrait
{
    /**
     * scopeApp.
     *
     */
    public function scopeFromPublicApp(Builder $query): Builder
    {
        return $query->where('apps_id', '=', 0);
    }

    public function scopeFromCompanyAndGlobal(Builder $query, mixed $company = null): Builder
    {
        $company = $company instanceof Companies
            ? $company
            : auth()->user()->getCurrentCompany();
    
        return $query->whereIn('companies_id', [0, $company->getId()]);
    }
}
