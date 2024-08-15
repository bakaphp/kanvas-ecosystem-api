<?php

declare(strict_types=1);

namespace Kanvas\Social\Models;

use Baka\Traits\KanvasModelTrait;
use Baka\Traits\KanvasScopesTrait;
use GeneaLabs\LaravelModelCaching\Traits\Cachable;
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
    //use Cachable;

    //use SoftDeletes;

    protected $attributes = [
        'is_deleted' => 0,
    ];

    protected $connection = 'social';
    //protected $is_deleted;
    public const DELETED_AT = 'is_deleted';
}
