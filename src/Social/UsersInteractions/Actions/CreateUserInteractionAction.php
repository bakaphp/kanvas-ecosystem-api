<?php

declare(strict_types=1);

namespace Kanvas\Social\UsersInteractions\Actions;

use Kanvas\Social\UsersInteractions\DataTransferObject\UserInteraction as UserInteractionDto;
use Kanvas\Social\UsersInteractions\Models\UserInteraction;

class CreateUserInteractionAction
{
    public function __construct(
        protected UserInteractionDto $userInteractionData
    ) {
    }

    public function execute(): UserInteraction
    {
        $userInteraction = UserInteraction::firstOrCreate([
            'users_id' => $this->userInteractionData->user->getId(),
            'entity_id' => $this->userInteractionData->entity_id,
            'entity_namespace' => $this->userInteractionData->entity_namespace,
            'interactions_id' => $this->userInteractionData->interaction->getId(),
        ], [
            'notes' => $this->userInteractionData->notes,
        ]);

        $this->addToCache($userInteraction);

        return $userInteraction;
    }

    protected function addToCache(UserInteraction $userInteraction): void
    {
        $currentData = $this->userInteractionData->user->get($userInteraction->getCacheKey()) ?? [];
        $currentData[$this->userInteractionData->interaction->name] = true;

        $this->userInteractionData->user->set(
            $userInteraction->getCacheKey(),
            $currentData
        );
    }
}
