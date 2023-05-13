<?php

declare(strict_types=1);

namespace Baka\Traits;

use Baka\Enums\StateEnums;
use Illuminate\Database\Eloquent\Builder;

trait NotDeletedScopesTrait
{
    /**
     * Not deleted scope.
     */
    public function scopeNotDeleted(Builder $query): Builder
    {
        return $query->where('is_deleted', '=', StateEnums::NO->getValue());
    }
}
