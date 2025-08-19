<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Models;

use Baka\Traits\HashTableTrait;
use Baka\Traits\UuidTrait;
use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Kanvas\Models\BaseModel;
use Rennokki\QueryCache\Traits\QueryCacheable;

/**
 * Filesystem Model.
 *
 * @property int $id
 * @property string $uuid;
 * @property int $users_id
 * @property int $companies_id
 * @property int $apps_id
 * @property string $name
 * @property string $path
 * @property string $url
 * @property string $size
 * @property string $file_type
 * @property string $created_at
 * @property string $updated_at
 * @property int $is_deleted
 */
class Filesystem extends BaseModel
{
    use UuidTrait;
    use HashTableTrait;
    //use Cachable;
    use QueryCacheable;

    public $cacheFor = 604800; //1 week
    public $cacheTags = ['filesystem'];
    public $cachePrefix = 'filesystem_';
    public $cacheDriver = 'redis';
    protected static $flushCacheOnUpdate = true;
    public ?string $macroKey = null;
    public mixed $withoutAllGlobalScopes;

    protected $table = 'filesystem';
    protected $fillable = [
        'users_id',
        'apps_id',
        'name',
        'path',
        'url',
        'size',
        'file_type',
    ];
    public $timestamps = true;

    public function settings(): HasMany
    {
        return $this->hasMany(FilesystemSettings::class, 'apps_id');
    }

    protected function createSettingsModel(): void
    {
        $this->settingsModel = new FilesystemSettings();
    }

    public function createdAt(): Carbon
    {
        return ! empty($this->created_at) ? $this->created_at : now();
    }
}
