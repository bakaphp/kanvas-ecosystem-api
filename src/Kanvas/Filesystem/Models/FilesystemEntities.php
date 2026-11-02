<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Models;

use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Kanvas\Filesystem\Observers\FilesystemEntitiesObserver;
use Kanvas\Models\BaseModel;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Workflow\Traits\CanUseWorkflow;

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
#[ObservedBy([FilesystemEntitiesObserver::class])]
class FilesystemEntities extends BaseModel
{
    use UuidTrait;
    use CanUseWorkflow;

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

    /**
     * Resolved without app/company scopes on purpose: the row already pins the entity, and the
     * callers (observers, queue workers) may not have the entity's app bound.
     */
    public function resolveEntity(): ?Model
    {
        $modelName = $this->systemModule?->model_name;

        if ($modelName === null || ! class_exists($modelName)) {
            return null;
        }

        return $modelName::query()->whereKey($this->entity_id)->first();
    }

    public function clearEntityLightHouseCache(): void
    {
        $entity = $this->resolveEntity();

        if ($entity !== null && method_exists($entity, 'clearLightHouseCache')) {
            $entity->clearLightHouseCache(withKanvasConfiguration: false);
        }
    }
}
