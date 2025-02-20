<?php

declare(strict_types=1);

namespace Kanvas\Users\Repositories;

use Baka\Contracts\AppInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Social\Interactions\Models\UsersInteractions;
use Kanvas\Social\Enums\InteractionEnum;
use Kanvas\Social\Interactions\Models\Interactions;
use Kanvas\Users\Models\Users;

class UsersInteractionsRepository
{

    public static function getUserLikedTagsByInteractions(string $entityNamespace, array $interactionNamesArray, Users $user, Companies $company, ?AppInterface $app = null): array
    {

        $interactionsList = [
            InteractionEnum::VIEW->getValue(),
            InteractionEnum::LIKE->getValue(),
            InteractionEnum::DISLIKE->getValue(),
            InteractionEnum::SAVE->getValue(),
            InteractionEnum::PURCHASE->getValue(),
        ];
        $interactionIdsArray = Interactions::fromApp($app)
            ->whereIn('name', $interactionNamesArray)
            ->where('is_deleted', 0)
            ->pluck('id')
            ->toArray();

        $userLikedTagsArray = [];

        //Get the tags the user is following
        $userInteraction = UsersInteractions::fromApp($app)
            ->where('users_id', $user->getId())
            ->where("entity_namespace", $entityNamespace)
            ->where('is_deleted', 0)
            ->where('interactions_id', $interactionIdsArray)
            ->get();

        // We need to get the liked messages and get the tags from them to know what the user is following and what it likes
        foreach ($userInteraction as $likeInteraction) {

            if (!$likeInteraction->entity) {
                continue;
            }

            $userLikedTags = $likeInteraction->entity->tags()->where('companies_id', $company->getId())->pluck('slug')->toArray();
            foreach ($userLikedTags as $tagArray) {

                if ($tagArray == null) {
                    continue;
                }

                $userLikedTagsArray[] = $tagArray;
            }
        }

        return array_unique($userLikedTagsArray);
    }
}
