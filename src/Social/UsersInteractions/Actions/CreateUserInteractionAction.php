<?php

declare(strict_types=1);

namespace Kanvas\Social\UsersInteractions\Actions;

use Baka\Support\Str;
use Illuminate\Support\Facades\Redis;
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
        $this->addToCache();

        return UserInteraction::firstOrCreate([
            'users_id' => $this->userInteractionData->user->getId(),
            'entity_id' => $this->userInteractionData->entity_id,
            'entity_namespace' => $this->userInteractionData->entity_namespace,
            'interactions_id' => $this->userInteractionData->interaction->getId(),
        ], [
            'notes' => $this->userInteractionData->notes,
        ]);
    }

    protected function addToCache(): void
    {
        $key = 'user_interactions:' . $this->userInteractionData->user->getId();
        $hashKey = Str::simpleSlug($this->userInteractionData->entity_namespace) . '-' . $this->userInteractionData->entity_id;
        $currentData = Redis::hGet($key, $hashKey) ?? [];
        $currentData[$this->userInteractionData->interaction->name] = true;

        Redis::hSet(
            $key,
            $hashKey,
            $currentData
        );
    }
}
