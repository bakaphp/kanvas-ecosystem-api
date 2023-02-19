<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Models\BaseModel;
use Kanvas\SystemModules\Models\SystemModules;

/**
 * Apps Model.
 *
 * @property int $id
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
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'filesystem_entities';

    protected $fillable = [
        'filesystem_id',
        'companies_id',
        'system_modules_id',
        'entity_id',
        'field_name',
        'is_deleted',
    ];

    /**
     * Filesystem relationship.
     *
     * @return Filesystem
     */
    public function filesystem(): BelongsTo
    {
        return $this->belongsTo(Filesystem::class, 'filesystem_id');
    }

    /**
     * Companies relationship.
     *
     * @return Companies
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Companies::class, 'companies_id');
    }

    /**
     * Apps relationship.
     *
     * @return Apps
     */
    public function app(): BelongsTo
    {
        return $this->belongsTo(Apps::class, 'apps_id');
    }

    /**
     * SystemModules relationship.
     *
     * @return SystemModules
     */
    public function systemModule(): BelongsTo
    {
        return $this->belongsTo(SystemModules::class, 'system_modules_id');
    }
}
