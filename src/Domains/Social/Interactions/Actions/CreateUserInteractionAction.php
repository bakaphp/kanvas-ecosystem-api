<?php

declare(strict_types=1);

namespace Kanvas\Social\Interactions\Actions;

use Kanvas\Social\Interactions\DataTransferObject\UserInteraction as UserInteractionDto;
use Kanvas\Social\UsersInteractions\Models\UserInteraction;
use Kanvas\Users\Enums\UserConfigEnum;

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
        $currentData = $this->userInteractionData->user->get(UserConfigEnum::USER_INTERACTIONS->value) ?? [];
        $currentData[$userInteraction->getCacheKey()][$this->userInteractionData->interaction->name] = true;

        $this->userInteractionData->user->set(
            UserConfigEnum::USER_INTERACTIONS->value,
            $currentData
        );
    }
}
