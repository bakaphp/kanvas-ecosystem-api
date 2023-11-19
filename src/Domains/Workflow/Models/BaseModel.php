<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Models;

use Baka\Traits\KanvasModelTrait;
use Baka\Traits\KanvasScopesTrait;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Kanvas\CustomFields\Traits\HasCustomFields;
use Kanvas\Filesystem\Traits\HasFilesystemTrait;
use Kanvas\Traits\SoftDeletes;

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

    protected $connection = 'workflow';
    public const DELETED_AT = 'is_deleted';
}
