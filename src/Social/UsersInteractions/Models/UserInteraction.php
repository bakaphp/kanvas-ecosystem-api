<?php

declare(strict_types=1);

namespace Kanvas\Social\UsersInteractions\Models;

use Baka\Support\Str;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Social\Interactions\Models\Interactions;
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
class UserInteraction extends BaseModel
{
    protected $table = 'users_interactions';

    protected $guarded = [];

    public function interactions(): BelongsTo
    {
        return $this->belongsTo(Interactions::class, 'interactions_id', 'id');
    }

    public function getCacheKey(): string
    {
        return Str::simpleSlug($this->entity_namespace) . '-' . $this->entity_id;
    }
}
