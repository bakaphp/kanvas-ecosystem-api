<?php

declare(strict_types=1);

namespace Baka\Traits;

use Illuminate\Database\Eloquent\Builder;

trait PublicAppScopeTrait
{
    /**
     * scopeApp.
     *
     */
    public function scopeFromPublicApp(Builder $query): Builder
    {
        return $query->orWhere('apps_id', '=', 0);
    }
}
