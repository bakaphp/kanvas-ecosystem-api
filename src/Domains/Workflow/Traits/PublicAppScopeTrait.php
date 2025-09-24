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
     */
    public function scopeFromPublicApp(Builder $query): Builder
    {
        return $query->where('apps_id', '=', 0);
    }

    public function scopeFromPublicOrCurrentApp(Builder $query, mixed $app = null): Builder
    {
        $app = $app instanceof Apps ? $app : app(Apps::class);

        return $query->whereIn('apps_id', [0, $app->getId()]);
    }
}
