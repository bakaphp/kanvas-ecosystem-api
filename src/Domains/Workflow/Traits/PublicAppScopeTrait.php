<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Traits;

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
