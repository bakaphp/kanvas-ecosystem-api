<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Models;

use Baka\Traits\KanvasModelTrait;
use Baka\Traits\KanvasScopesTrait;
use Baka\Traits\SoftDeletesTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Kanvas\CustomFields\Traits\HasCustomFields;
use Kanvas\Filesystem\Traits\HasFilesystemTrait;
use Kanvas\Inventory\Traits\AppsIdTrait;
use Kanvas\Inventory\Traits\SourceTrait;

class BaseModel extends EloquentModel
{
    use AppsIdTrait;
    use HasCustomFields;
    use HasFactory;
    use HasFilesystemTrait;
    use KanvasModelTrait;
    use KanvasScopesTrait;
    use SoftDeletesTrait;
    use SourceTrait;

    protected $attributes = [
        'is_deleted' => 0,
    ];

    protected $casts = [
        'is_deleted' => 'boolean',
    ];

    public const DELETED_AT = 'is_deleted';

    protected $connection = 'intelligence';

    public function trashed()
    {
        return (bool) $this->{$this->getDeletedAtColumn()};
    }
}
