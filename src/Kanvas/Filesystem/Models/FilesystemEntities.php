<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Models;

use Baka\Traits\UuidTrait;
use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Models\BaseModel;
use Kanvas\SystemModules\Models\SystemModules;

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
    use Cachable;

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
     * @return BelongsTo
     */
    public function filesystem(): BelongsTo
    {
        return $this->belongsTo(Filesystem::class, 'filesystem_id');
    }

    /**
     * Companies relationship.
     *
     * @return BelongsTo
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Companies::class, 'companies_id');
    }

    /**
     * Apps relationship.
     *
     * @return BelongsTo
     */
    public function app(): BelongsTo
    {
        return $this->belongsTo(Apps::class, 'apps_id');
    }

    /**
     * SystemModules relationship.
     *
     * @return BelongsTo
     */
    public function systemModule(): BelongsTo
    {
        return $this->belongsTo(SystemModules::class, 'system_modules_id');
    }
}
