<?php
declare(strict_types=1);

namespace Baka\Traits;

use Illuminate\Database\Eloquent\Builder;

trait NoAppRelationshipTrait
{
    /**
     * @override Entity doesn't have apps_id
     */
    public function scopeFromApp(Builder $query, mixed $app = null) : Builder
    {
        return $query;
    }

    /**
     * @override Entity doesn't have apps_id
     */
    public static function bootAppsIdTrait()
    {
    }
}
