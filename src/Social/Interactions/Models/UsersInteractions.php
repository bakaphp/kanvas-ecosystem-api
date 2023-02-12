<?php

declare(strict_types=1);

namespace Kanvas\Social\Interactions\Models;

use Baka\Traits\MorphEntityDataTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Social\Models\BaseModel;

/**
 * Class Interactions.
 *
 * @property int $id
 * @property int $users_id
 * @property string $entity_id
 * @property string $entity_namespace
 * @property string $interactions_id
 * @property string $notes
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
}
