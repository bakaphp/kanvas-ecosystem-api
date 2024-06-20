<?php

declare(strict_types=1);

namespace Kanvas\Social\Interactions\Actions;

use Baka\Enums\StateEnums;
use Baka\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Interactions\DataTransferObject\EntityInteraction;
use Kanvas\Social\Interactions\DataTransferObject\Interaction;
use Kanvas\Social\Interactions\Models\EntityInteractions;

class CreateEntityInteractionAction
{
    public function __construct(
        protected EntityInteraction $entityInteractionData,
        protected Apps $app
    ) {
    }

    public function execute(): EntityInteractions
    {
        $createInteractions = new CreateInteraction(
            new Interaction(
                $this->entityInteractionData->interaction,
                $this->app,
                ucfirst($this->entityInteractionData->interaction),
            )
        );

        $interaction = $createInteractions->execute();
  
        return EntityInteractions::updateOrCreate(
            [
                'entity_id' => $this->entityInteractionData->entity->uuid,
                'entity_namespace' => get_class($this->entityInteractionData->entity),
                'interactions_id' => $interaction->getId(),
                'interacted_entity_id' => $this->entityInteractionData->interactedEntity->uuid,
                'interacted_entity_namespace' => get_class($this->entityInteractionData->interactedEntity),
            ],
            [
                'notes' => $this->entityInteractionData->note,
                'is_deleted' => StateEnums::NO->getValue(),
            ]
        );
    }
}
