<?php

declare(strict_types=1);

namespace Kanvas\Social\Interactions\Traits;

use Kanvas\Social\Interactions\DataTransferObject\LikeEntityInput;
use Kanvas\Social\Interactions\Models\Interactions;
use Kanvas\Social\Interactions\Repositories\EntityInteractionsRepository;
use Kanvas\Users\Enums\UserConfigEnum;

trait SocialInteractionsTrait
{
    /**
     * Given a visitorInput get the social interactions for the entity.
     *
     * @param array $visitorInput<string,string>
     *
     * @return array<array-key,bool> #graph Interactions
     */
    public function getEntitySocialInteractions(array $visitorInput): array
    {
        return EntityInteractionsRepository::getEntityInteractions(
            new LikeEntityInput(
                $visitorInput['id'],
                $visitorInput['type'],
                $this->uuid,
                static::class
            )
        );
    }

    public function getUserSocialInteractions(): array
    {
        //@todo i hate this, lets look for a better way to get the current user
        $user = auth()->user();
        $userInteractions = $user->get(UserConfigEnum::USER_INTERACTIONS->value) ?? [];

        return $userInteractions[$this->getCacheKey()] ?? [];
    }
}
