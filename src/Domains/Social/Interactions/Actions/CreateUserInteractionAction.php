<?php

declare(strict_types=1);

namespace Kanvas\Social\Interactions\Actions;

use Kanvas\Social\Enums\InteractionEnum;
use Kanvas\Social\Interactions\DataTransferObject\UserInteraction as UserInteractionDto;
use Kanvas\Social\Interactions\Models\UsersInteractions;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Enums\UserConfigEnum;

class CreateUserInteractionAction
{
    public function __construct(
        protected UserInteractionDto $userInteractionData
    ) {
    }

    public function execute(
        bool $allowDuplicate = false,
        bool $addToCache = true
    ): UsersInteractions {
        $userInteraction = $allowDuplicate
        ? UsersInteractions::create([
            'users_id' => $this->userInteractionData->user->getId(),
            'apps_id' => $this->userInteractionData->interaction->apps_id,
            'entity_id' => $this->userInteractionData->entity_id,
            'entity_namespace' => $this->userInteractionData->entity_namespace,
            'interactions_id' => $this->userInteractionData->interaction->getId(),
            'notes' => $this->userInteractionData->notes,
        ])
        : UsersInteractions::updateOrCreate([
            'users_id' => $this->userInteractionData->user->getId(),
            'apps_id' => $this->userInteractionData->interaction->apps_id,
            'entity_id' => $this->userInteractionData->entity_id,
            'entity_namespace' => $this->userInteractionData->entity_namespace,
            'interactions_id' => $this->userInteractionData->interaction->getId(),
        ], [
            'notes' => $this->userInteractionData->notes,
            'is_deleted' => 0,
        ]);

        if ($addToCache) {
            $this->addToCache($userInteraction);
        }

        return $userInteraction;
    }

    protected function addToCache(UsersInteractions $userInteraction): void
    {
        $currentData = $this->userInteractionData->user->get(UserConfigEnum::USER_INTERACTIONS->value) ?? [];
        $excludedInteractionsFromCache = [InteractionEnum::SHARE->getValue(), InteractionEnum::VIEW->getValue()];

        if (is_array($currentData) && ! in_array($this->userInteractionData->interaction->name, $excludedInteractionsFromCache)) {
            $currentData[$userInteraction->getCacheKey()][$this->userInteractionData->interaction->name] = true;

            $this->userInteractionData->user->set(
                UserConfigEnum::USER_INTERACTIONS->value,
                $currentData
            );
        }
    }

    protected function clearEntityCache(UsersInteractions $userInteraction): void
    {
        $entity = $userInteraction->entityData();
        $includedNamespace = [
            Message::class,
        ];

        if (in_array($userInteraction->entity_namespace, $includedNamespace) && $entity && method_exists($entity, 'flushCache')) {
            $entity->flushCache();
        }
    }
}
