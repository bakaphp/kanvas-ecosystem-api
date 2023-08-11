<?php

declare(strict_types=1);

namespace Kanvas\Social\Reactions\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Social\Models\BaseModel;

/**
 * class UserReaction
 * @property int $id
 * @property int $users_id
 * @property int $reactions_id
 * @property string $entity_id
 * @property int $entity_namespace
 */
class UserReaction extends BaseModel
{
    protected $table = 'users_reactions';

    protected $guarded = [];

    public function reaction(): BelongsTo
    {
        return $this->belongsTo(Reaction::class, 'reactions_id', );
    }
}
