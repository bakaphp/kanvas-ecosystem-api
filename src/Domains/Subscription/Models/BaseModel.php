<?php

declare(strict_types=1);

namespace Kanvas\Subscription\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Baka\Traits\KanvasModelTrait;
use Baka\Traits\KanvasScopesTrait;
use Baka\Traits\SoftDeletesTrait;

class BaseModel extends EloquentModel
{
    use HasFactory;
    use KanvasModelTrait;
    use KanvasScopesTrait;
    use SoftDeletesTrait;

    protected $subscriptions = [
        'is_deleted' => 0,
    ];

    /**
     * Prevent laravel from cast is_deleted as date using carbon.
     *
     */
    protected $casts = [
        'is_deleted' => 'boolean',
    ];

    protected $connection = 'mysql';

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
