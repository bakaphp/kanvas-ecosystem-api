<?php

declare(strict_types=1);

namespace Baka\Traits;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Companies\Models\Companies;

trait KanvasCompanyScopesTrait
{
    /**
     * scopeCompany.
     *
     * @param mixed $company
     */
    public function scopeFromCompany(Builder $query, mixed $company = null): Builder
    {
        $company = $company instanceof Companies ? $company : auth()->user()->getCurrentCompany();

        return $query->where('companies_id', $company->getId());
    }
}
