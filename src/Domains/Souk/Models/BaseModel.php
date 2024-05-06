<?php

declare(strict_types=1);

namespace Kanvas\Souk\Models;

use Baka\Traits\KanvasModelTrait;
use Baka\Traits\KanvasScopesTrait;
use Baka\Traits\SoftDeletesTrait;
use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Kanvas\CustomFields\Traits\HasCustomFields;
use Kanvas\Filesystem\Traits\HasFilesystemTrait;
use Kanvas\Inventory\Traits\AppsIdTrait;
use Kanvas\Inventory\Traits\CompaniesIdTrait;
use Kanvas\Inventory\Traits\SourceTrait;

class BaseModel extends EloquentModel
{
    use HasFactory;
    use SourceTrait;
    use KanvasModelTrait;
    use AppsIdTrait;
    use CompaniesIdTrait;
    use KanvasScopesTrait;
    use HasCustomFields;
    use HasFilesystemTrait;
    //use Cachable;
    use SoftDeletesTrait;

    protected $attributes = [
        'is_deleted' => 0,
    ];

    protected $connection = 'commerce';

    public const DELETED_AT = 'is_deleted';
}
