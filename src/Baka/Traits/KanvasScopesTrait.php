<?php

declare(strict_types=1);

namespace Baka\Traits;

use Baka\Enums\StateEnums;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;

trait KanvasScopesTrait
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

    /**
     * scopeApp.
     *
     * @param mixed $app
     */
    public function scopeFromApp(Builder $query, mixed $app = null): Builder
    {
        $app = $app instanceof Apps ? $app : app(Apps::class);

        return $query->where('apps_id', $app->getId());
    }

    /**
     * Not deleted scope.
     */
    public function scopeNotDeleted(Builder $query): Builder
    {
        return $query->where('is_deleted', '=', StateEnums::NO->getValue());
    }

    /**
     * Is public scope.
     */
    public function scopeIsPublic(Builder $query): Builder
    {
        return $query->where('is_public', '=', StateEnums::YES->getValue());
    }
}
