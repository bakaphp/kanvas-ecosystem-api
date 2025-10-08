<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Models;

use Baka\Traits\UuidTrait;
use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Kanvas\Models\BaseModel;
use Kanvas\SystemModules\Models\SystemModules;
use Rennokki\QueryCache\Traits\QueryCacheable;

/**
 * FilesystemEntities Model.
 *
 * @property int $id
 * @property string $uuid;
 * @property int $filesystem_id
 * @property int $companies_id
 * @property int $system_modules_id
 * @property int $entity_id
 * @property string $field_name
 * @property string $created_at
 * @property string $updated_at
 * @property int $is_deleted
 */
class FilesystemEntities extends BaseModel
{
    use UuidTrait;
    //use Cachable;
    // use QueryCacheable;

    public $cacheFor = 604800; //1 week
    public $cacheTags = ['filesystemEntity'];
    public $cachePrefix = 'filesystemEntity_';
    public $cacheDriver = 'redis';
    //    protected static $flushCacheOnUpdate = true;

    protected $table = 'filesystem_entities';
    // protected $touches = ['filesystem'];

    protected $fillable = [
        'filesystem_id',
        'companies_id',
        'system_modules_id',
        'entity_id',
        'field_name',
        'weight',
        'is_deleted',
    ];

    /**
     * Filesystem relationship.
     */
    public function filesystem(): BelongsTo
    {
        return $this->belongsTo(Filesystem::class, 'filesystem_id');
    }

    /**
     * SystemModules relationship.
     */
    public function systemModule(): BelongsTo
    {
        return $this->belongsTo(SystemModules::class, 'system_modules_id');
    }

    public function createdAt(): Carbon
    {
        return ! empty($this->created_at) ? $this->created_at : now();
    }
}
