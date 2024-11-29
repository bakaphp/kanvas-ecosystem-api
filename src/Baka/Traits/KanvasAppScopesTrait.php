<?php

declare(strict_types=1);

namespace Baka\Traits;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Illuminate\Database\Eloquent\Model;
trait KanvasAppScopesTrait
{
    /**
     * scopeApp.
     *
     * @param mixed $app
     */
    public function scopeFromApp(Builder $query, mixed $app = null): Builder
    {
        $table = $this instanceof Model ? $this->getTable() . '.': '';

        $app = $app instanceof Apps ? $app : app(Apps::class);

        return $query->where($table.'apps_id', $app->getId());
    }
}
