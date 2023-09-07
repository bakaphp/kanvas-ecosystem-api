<?php

declare(strict_types=1);

namespace Kanvas\Social\UsersInteractions\Models;

use Kanvas\Social\Interactions\DataTransferObject\LikeEntityInput;
use Kanvas\Social\Interactions\Models\Interactions;
use Kanvas\Social\Interactions\Repositories\EntityInteractionsRepository;
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

    /**
     * Get the grouped interactions for this current entity.
     */
    public function getGroupInteractions(): array
    {
        return EntityInteractionsRepository::getEntityInteractions(
            LikeEntityInput::from([
                'entity_id' => $this->entity_id,
                'entity_namespace' => $this->entity_namespace,
                'interacted_entity_id' => $this->interacted_entity_id,
                'interacted_entity_namespace' => $this->interacted_entity_namespace,
            ])
        );
    }
}
