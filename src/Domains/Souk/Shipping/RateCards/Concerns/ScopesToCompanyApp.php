<?php

declare(strict_types=1);

namespace Kanvas\Souk\Shipping\RateCards\Concerns;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Database\Eloquent\Builder;

trait ScopesToCompanyApp
{
    public function scopeOwnedBy(Builder $query, AppInterface $app, CompanyInterface $company): Builder
    {
        return $query
            ->where($this->getTable() . '.companies_id', $company->getId())
            ->where($this->getTable() . '.apps_id', $app->getId());
    }
}
