<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Models\BaseModel;

/**
 * Apps Model.
 *
 * @property int $id
 * @property int $filesystem_id
 * @property string $name
 * @property string $value
 * @property string $created_at
 * @property string $updated_at
 * @property int $is_deleted
 */
class FilesystemSettings extends BaseModel
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'filesystem_settings';

    /**
     * Filesystem relationship.
     *
     * @return Filesystem
     */
    public function filesystem() : BelongsTo
    {
        return $this->belongsTo(FileSystem::class, 'filesystem_id');
    }
}
