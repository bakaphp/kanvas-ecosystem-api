<?php

declare(strict_types=1);

namespace Baka\Traits;

use Baka\Enums\StateEnums;
use Illuminate\Database\Eloquent\Builder;

trait IsPublicScopesTrait
{
    /**
    * Is public scope.
    */
    public function scopeIsPublic(Builder $query): Builder
    {
        return $query->where('is_public', '=', StateEnums::YES->getValue());
    }
}
