<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Models\BaseModel;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Traits\DefaultTrait;

/**
 * FilesystemMapping Model.
 *
 * @property int $id
 * @property string $uuid;
 * @property int $apps_id
 * @property int $users_id
 * @property int $companies_id
 * @property int $companies_branches_id
 * @property int $system_modules_id
 * @property int $name
 * @property array $file_header
 * @property array $mapping
 * @property string $created_at
 * @property string $updated_at
 * @property int $is_deleted
 */
class FilesystemMapper extends BaseModel
{
    use UuidTrait;
    use DefaultTrait;
    //use Cachable;

    protected $table = 'filesystem_mappers';
    //protected $touches = ['filesystem'];

    protected $fillable = [
        'apps_id',
        'users_id',
        'companies_id',
        'companies_branches_id',
        'system_modules_id',
        'name',
        'file_header',
        'mapping',
        'is_default',
    ];

    protected $casts = [
        'file_header' => Json::class,
        'mapping' => Json::class,
    ];

    /**
     * SystemModules relationship.
     */
    public function systemModule(): BelongsTo
    {
        return $this->belongsTo(SystemModules::class, 'system_modules_id');
    }

    public function imports(): HasMany
    {
        return $this->hasMany(FilesystemImports::class, 'filesystem_mapper_id', 'id');
    }
}
