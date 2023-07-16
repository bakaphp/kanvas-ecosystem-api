<?php


declare(strict_types=1);

namespace Baka\Traits;

use Illuminate\Database\Eloquent\Builder;

trait KanvasUsersScopesTrait
{
    public function scopeFromUser(Builder $query, mixed $app): Builder
    {
        return $query->where('users_id', auth()->user()->id);
    }
}
