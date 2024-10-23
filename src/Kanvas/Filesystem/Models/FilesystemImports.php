<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\CustomFields\Traits\HasCustomFields;
use Kanvas\Filesystem\Observers\FilesystemImportObserver;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Models\BaseModel;

/**
 * @property int $id
 * @property string $uuid
 * @property int $apps_id
 * @property int $users_id
 * @property int $regions_id
 * @property int $companies_id
 * @property int $companies_branches_id
 * @property int $filesystem_id
 * @property int $filesystem_mapper_id
 * @property string $results
 * @property string $exception
 * @property string $created_at
 * @property string $updated_at
 */
#[ObservedBy([FilesystemImportObserver::class])]
class FilesystemImports extends BaseModel
{
    use UuidTrait;
    use HasCustomFields;

    public $table = 'filesystem_imports';
    protected $guarded = [];

    public function casts(): array
    {
        return [
            'results' => Json::class,
            'exception' => Json::class,
        ];
    }

    public function regions(): BelongsTo
    {
        return $this->belongsTo(Regions::class, 'regions_id', 'id');
    }

    public function filesystemMapper(): BelongsTo
    {
        return $this->belongsTo(FilesystemMapper::class, 'filesystem_mapper_id', 'id');
    }

    public function filesystem(): BelongsTo
    {
        return $this->belongsTo(Filesystem::class, 'filesystem_id', 'id');
    }

    public function companiesBranches(): BelongsTo
    {
        return $this->belongsTo(CompaniesBranches::class, 'companies_branches_id', 'id');
    }
}
