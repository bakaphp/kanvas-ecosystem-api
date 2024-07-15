<?php

declare(strict_types=1);

namespace Kanvas\Social\Interactions\Actions;

use Baka\Enums\StateEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Interactions\DataTransferObject\Interaction;
use Kanvas\Social\Interactions\DataTransferObject\LikeEntityInput;
use Kanvas\Social\Interactions\Models\EntityInteractions;

/**
 * @deprecated v1.0
 */
class CreateEntityInteraction
{
    public function __construct(
        protected LikeEntityInput $entityInteractionData,
        protected Apps $app
    ) {
    }

    /**
     * execute.
     */
    public function execute(string $interactionType = 'like'): EntityInteractions
    {
        $createInteractions = new CreateInteraction(
            new Interaction(
                $interactionType,
                $this->app,
                ucfirst($interactionType),
            )
        );
        $interaction = $createInteractions->execute();

        /**
         * @var EntityInteractions
         */
        return EntityInteractions::updateOrCreate(
            [
                'entity_id' => $this->entityInteractionData->entity_id,
                'entity_namespace' => $this->entityInteractionData->entity_namespace,
                'interactions_id' => $interaction->getId(),
                'interacted_entity_id' => $this->entityInteractionData->interacted_entity_id,
                'interacted_entity_namespace' => $this->entityInteractionData->interacted_entity_namespace,
            ],
            [
                'is_deleted' => StateEnums::NO->getValue(),
            ]
        );
    }
}
