<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Traits;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;

trait PublicAppScopeTrait
{
    /**
     * scopeApp.
     *
     * @param mixed $app
     */
    public function scopeFromPublicApp(Builder $query): Builder
    {
        return $query->orWhere('apps_id', '=', 0);
    }
}
