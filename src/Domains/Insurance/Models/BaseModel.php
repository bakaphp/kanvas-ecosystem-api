<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Models;

use Baka\Traits\KanvasModelTrait;
use Baka\Traits\SoftDeletesTrait;
use Illuminate\Database\Eloquent\Model as EloquentModel;

class BaseModel extends EloquentModel
{
    use KanvasModelTrait;
    use SoftDeletesTrait;

    public const DELETED_AT = 'is_deleted';

    protected $connection = 'commerce';

    protected $guarded = [];

    protected $attributes = [
        'is_deleted' => 0,
    ];

    protected $casts = [
        'is_deleted' => 'boolean',
    ];

    public function trashed()
    {
        return (bool) $this->{$this->getDeletedAtColumn()};
    }
}
