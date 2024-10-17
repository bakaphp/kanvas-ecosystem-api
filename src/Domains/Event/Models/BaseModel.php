<?php

declare(strict_types=1);

namespace Kanvas\Event\Models;

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
use Kanvas\Inventory\Traits\DefaultTrait;
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
    // use Cachable;
    use SoftDeletesTrait;
    use DefaultTrait;

    protected $attributes = [
        'is_deleted' => 0,
    ];

    /**
     * Prevent laravel from cast is_deleted as date using carbon.
     */
    protected $casts = [
        'is_deleted' => 'boolean',
    ];

    protected $connection = 'event';

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
