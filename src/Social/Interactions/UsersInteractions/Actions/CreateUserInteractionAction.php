<?php

declare(strict_types=1);

namespace Kanvas\Social\Interactions\UsersInteractions\Actions;

use Kanvas\Social\Interactions\UsersInteractions\DataTransferObject\UserInteraction as UserInteractionDto;
use Kanvas\Social\Interactions\UsersInteractions\Models\UserInteraction;

class CreateUserInteractionAction
{
    public function __construct(
        protected UserInteractionDto $userInteractionData
    ) {
    }

    public function execute(): UserInteraction
    {
        return UserInteraction::firstOrCreate([
            'users_id' => $this->userInteractionData->user->getId(),
            'entity_id' => $this->userInteractionData->entity_id,
            'entity_namespace' => $this->userInteractionData->entity_namespace,
            'interactions_id' => $this->userInteractionData->interaction->getId(),
            'notes' => $this->userInteractionData->notes,
        ]);
    }
}
