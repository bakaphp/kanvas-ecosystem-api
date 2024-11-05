<?php

declare(strict_types=1);

namespace Kanvas\Models;

use Baka\Traits\KanvasModelTrait;
use Baka\Traits\KanvasScopesTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Kanvas\Traits\SoftDeletes;

class BaseModel extends EloquentModel
{
    use HasFactory;
    use KanvasModelTrait;
    use KanvasScopesTrait;
    //use SoftDeletes;

    protected $connection = 'ecosystem';

    protected $attributes = [
        'is_deleted' => 0,
    ];

    /**
     * Prevent laravel from cast is_deleted as date using carbon.
     *
     */
    protected $casts = [
        'is_deleted' => 'boolean',
    ];

    public const DELETED_AT = 'is_deleted';

    /**
     * Determine if the model instance has been soft-deleted.
     *
     * @return bool
     */
    public function trashed()
    {
        return $this->{$this->getDeletedAtColumn()};
    }
}
