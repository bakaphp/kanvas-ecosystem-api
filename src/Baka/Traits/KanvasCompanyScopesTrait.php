<?php

declare(strict_types=1);

namespace Baka\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\AppKey;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;

trait KanvasCompanyScopesTrait
{
    /**
     * scopeCompany.
     */
    public function scopeFromCompany(Builder $query, mixed $company = null): Builder
    {
        $company = $company instanceof Companies ? $company : auth()->user()->getCurrentCompany();

        $table = $this instanceof Model ? $this->getTable() . '.' : '';

        if (app()->bound(AppKey::class) && ! app()->bound(CompaniesBranches::class)) {
            return $query->where($table . 'companies_id', '>', 0);
        }

        return $query->where($table . 'companies_id', $company->getId());
    }
}
