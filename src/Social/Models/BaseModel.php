<?php

declare(strict_types=1);

namespace Kanvas\Social\Models;

use Baka\Traits\KanvasModelTrait;
use Baka\Traits\KanvasScopesTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Kanvas\CustomFields\Traits\HasCustomFields;
use Kanvas\Enums\StateEnums;
use Kanvas\Filesystem\Traits\HasFilesystemTrait;
use Kanvas\Traits\SoftDeletes;
use Throwable;

class BaseModel extends EloquentModel
{
    use KanvasModelTrait;
    use KanvasScopesTrait;
    use HasCustomFields;
    use HasFilesystemTrait;

    //use SoftDeletes;

    protected $attributes = [
        'is_deleted' => 0,
    ];

    protected $connection = 'social';

    /**
     * Current soft delete.
     *
     * @return bool
     *
     * @throws Throwable
     *
     * @todo change to laravel default behavior
     *
     */
    public function softDelete() : bool
    {
        $this->is_deleted = StateEnums::YES->getValue();
        return $this->saveOrFail();
    }

    /**
     * Not deleted scope.
     *
     * @param Builder $query
     *
     * @return Builder
     */
    public function scopeNotDeleted(Builder $query) : Builder
    {
        return $query->where('is_deleted', '=', StateEnums::NO->getValue());
    }
}
