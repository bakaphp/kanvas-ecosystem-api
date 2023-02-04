<?php
declare(strict_types=1);

namespace Baka\Traits;

use Baka\Enums\StateEnums;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;

trait KanvasScopesTrait
{
    /**
     * scopeCompany.
     *
     * @param  Builder $query
     *
     * @return Builder
     */
    public function scopeCompany(Builder $query) : Builder
    {
        return $query->where('companies_id', auth()->user()->getCurrentCompany()->getId());
    }

    /**
     * scopeApp.
     *
     * @param  Builder $query
     *
     * @return Builder
     */
    public function scopeApp(Builder $query) : Builder
    {
        return $query->where('apps_id', app(Apps::class)->getId());
    }

    /**
     * Not deleted scope.
     *
     * @param Builder $query
     *
     * @return Builder
     */
    public function scopeNotDeleted(Builder $query) : Builder
    {
        return $query->where('is_deleted', '=', StateEnums::NO->getValue());
    }
}
