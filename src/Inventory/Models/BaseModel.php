<?php

declare(strict_types=1);
namespace Inventory\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Kanvas\Enums\StateEnums;
use Kanvas\Traits\SoftDeletes;

class BaseModel extends EloquentModel
{
    use HasFactory;
    //use SoftDeletes;

    protected $attributes = [
        'is_deleted' => 0,
    ];

    /**
     * Get by uui.
     *
     * @param string $uuid
     *
     * @return self
     */
    public static function getByUuid(string $uuid) : self
    {
        return self::where('id', $uuid)
            ->where('is_deleted', StateEnums::NO->getValue())
            ->firstOrFail();
    }

    /**
     * Get by Id.
     *
     * @param mixed $id
     *
     * @return self
     */
    public static function getById(mixed $id) : self
    {
        return self::where('id', (int) $id)
            ->where('is_deleted', StateEnums::NO->getValue())
            ->firstOrFail();
    }

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
