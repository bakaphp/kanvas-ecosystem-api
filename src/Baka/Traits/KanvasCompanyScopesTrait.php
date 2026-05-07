<?php

declare(strict_types=1);

namespace Baka\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\AppKey;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Souk\Enums\ConfigurationEnum as SoukConfigurationEnum;

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

    /**
     * Scope to return records owned by the current company OR app-global records (companies_id = 0).
     * Global entities are only included when the company has `use_global_inventory_entities` enabled.
     */
    public function scopeFromCompanyOrGlobal(Builder $query, mixed $company = null): Builder
    {
        $table = $this instanceof Model ? $this->getTable() . '.' : '';

        if (app()->bound(AppKey::class) && ! app()->bound(CompaniesBranches::class)) {
            return $query->where($table . 'companies_id', '>=', 0);
        }

        $company = $company instanceof Companies ? $company : auth()->user()->getCurrentCompany();
        $companyId = $company->getId();

        if (! app(Apps::class)->get(SoukConfigurationEnum::ALLOW_CROSS_COMPANY_VARIANTS->value)) {
            return $query->where($table . 'companies_id', $companyId);
        }

        return $query->where(function ($q) use ($table, $companyId) {
            $q->where($table . 'companies_id', 0)
              ->orWhere($table . 'companies_id', $companyId);
        });
    }
}
