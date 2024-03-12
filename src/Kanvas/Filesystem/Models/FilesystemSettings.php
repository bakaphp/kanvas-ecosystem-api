<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
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
    use Cachable;

    protected $table = 'filesystem_settings';
    protected $touches = ['filesystem'];

    /**
     * Filesystem relationship.
     */
    public function filesystem(): BelongsTo
    {
        return $this->belongsTo(Filesystem::class, 'filesystem_id');
    }
}
