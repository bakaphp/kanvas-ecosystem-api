<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

use function Illuminate\Events\queueable;

use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Filesystem\Actions\ImportDataFromFilesystemAction;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Models\BaseModel;

/**
 * @property int $id
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

class FilesystemImports extends BaseModel
{
    public $table = 'filesystem_imports';
    protected $guarded = [];

    public function casts(): array
    {
        return [
            'results' => 'array',
            'exception' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::created(queueable(function (FilesystemImports $import) {
            (new ImportDataFromFilesystemAction($import))->execute();
        }));
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
