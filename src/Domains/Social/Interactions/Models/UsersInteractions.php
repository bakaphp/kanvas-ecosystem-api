<?php

declare(strict_types=1);

namespace Kanvas\Social\Interactions\Models;

use Baka\Support\Str;
use Baka\Traits\MorphEntityDataTrait;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Social\Interactions\Observers\UserInteractionObserver;
use Kanvas\Social\Models\BaseModel;
use Kanvas\Workflow\Traits\CanUseWorkflow;

/**
 * @property int $id
 * @property int $apps_id
 * @property int $users_id
 * @property string $entity_id
 * @property string $entity_namespace
 * @property int $interactions_id
 * @property string $notes
 * @property string $created_at
 * @property string $updated_at
 * @property bool $is_deleted
 */
#[ObservedBy([UserInteractionObserver::class])]
class UsersInteractions extends BaseModel
{
    use MorphEntityDataTrait;
    use CanUseWorkflow;

    protected $table = 'users_interactions';
    protected $guarded = [];

    public function interaction(): BelongsTo
    {
        return $this->belongsTo(Interactions::class, 'interactions_id', 'id');
    }

    /**
     * @override
     */
    public function getCacheKey(): string
    {
        return Str::simpleSlug($this->entity_namespace) . '-' . $this->entity_id;
    }
}
