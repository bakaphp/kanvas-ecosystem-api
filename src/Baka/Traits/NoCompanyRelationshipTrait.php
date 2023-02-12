<?php
declare(strict_types=1);

namespace Baka\Traits;

use Illuminate\Database\Eloquent\Builder;

trait NoCompanyRelationshipTrait
{
    /**
     * @override Entity doesn't have companies_id
     */
    public function scopeFromCompany(Builder $query, mixed $company = null) : Builder
    {
        return $query;
    }

    /**
     * @override Entity doesn't have companies_id
     */
    public static function bootCompaniesIdTrait()
    {
    }
}
