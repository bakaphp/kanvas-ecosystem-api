<?php

declare(strict_types=1);
namespace Kanvas\Inventory\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Kanvas\Enums\StateEnums;
use Kanvas\Traits\SoftDeletes;
use Baka\Traits\BaseModel as BakaBaseModel;

class BaseModel extends EloquentModel
{
    use HasFactory, BakaBaseModel;
    //use SoftDeletes;

    protected $attributes = [
        'is_deleted' => 0,
    ];

    /**
     * Current soft delete.
     *
     * @todo change to laravel default behavior
     *
     * @return bool
     */
    public function softDelete() : bool
    {
        $this->is_deleted = StateEnums::YES->getValue();
        return $this->saveOrFail();
    }

    /**
     * Not deleted scope
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeNotDeleted(Builder $query) : Builder
    {
        return $query->where('is_deleted', '=', StateEnums::NO->getValue());
    }
}
