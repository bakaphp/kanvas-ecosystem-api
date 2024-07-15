<?php

declare(strict_types=1);

namespace Kanvas\Social\Interactions\Models;

use Baka\Traits\MorphEntityDataTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Social\Interactions\DataTransferObject\LikeEntityInput;
use Kanvas\Social\Interactions\Repositories\EntityInteractionsRepository;
use Kanvas\Social\Models\BaseModel;

/**
 * Class Interactions.
 *
 * @property int $id
 * @property string $entity_id
 * @property string $entity_namespace
 * @property string $interactions_id
 * @property string $interacted_entity_id
 * @property string $interacted_entity_namespace
 * @property string $notes
 */
class EntityInteractions extends BaseModel
{
    use MorphEntityDataTrait;

    protected $table = 'entity_interactions';
    protected $guarded = [];

    public function interaction(): BelongsTo
    {
        return $this->belongsTo(Interactions::class, 'interactions_id', 'id');
    }

    /**
     * Allow to access the interacted entity data.
     */
    public function interactedEntityData(): ?Model
    {
        /**
         * @var Model
         */
        return $this->interacted_entity_namespace::notDeleted()
            ->where('uuid', $this->interacted_entity_id)
            ->first();
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
