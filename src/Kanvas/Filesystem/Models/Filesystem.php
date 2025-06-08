<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Models;

use Baka\Traits\UuidTrait;
use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Support\Carbon;
use Kanvas\Models\BaseModel;

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
    use Cachable;

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

    public function createdAt(): Carbon
    {
        return ! empty($this->created_at) ? $this->created_at : now();
    }
}
