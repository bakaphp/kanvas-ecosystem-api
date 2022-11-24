<?php
declare(strict_types=1);
namespace Kanvas\Inventory\Traits;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;

trait ScopesTrait
{
    /**
     * scopeCompany
     *
     * @param  Builder $query
     * @return Builder
     */
    public function scopeCompany(Builder $query): Builder
    {
        return $query->where('companies_id', auth()->user()->default_company);
    }

    /**
     * scopeApp
     *
     * @param  Builder $query
     * @return Builder
     */
    public function scopeApp(Builder $query): Builder
    {
        return $query->where('apps_id', app(Apps::class)->id);
    }
}
