<?php

declare(strict_types=1);

namespace Kanvas\Social\Interactions\Models;

use Baka\Support\Str;
use Baka\Traits\MorphEntityDataTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Social\Models\BaseModel;

/**
 * @property int $id
 * @property int $users_id
 * @property string $entity_id
 * @property string $entity_namespace
 * @property int $interactions_id
 * @property string $notes
 * @property string $created_at
 * @property string $updated_at
 * @property bool $is_deleted
 */
class UsersInteractions extends BaseModel
{
    use MorphEntityDataTrait;

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
