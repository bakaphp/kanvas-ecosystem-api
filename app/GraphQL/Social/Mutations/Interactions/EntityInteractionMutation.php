<?php

declare(strict_types=1);

namespace App\GraphQL\Social\Mutations\Interactions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Enums\StateEnums;
use Kanvas\Social\Interactions\Actions\CreateEntityInteraction;
use Kanvas\Social\Interactions\DataTransferObject\LikeEntityInput;
use Kanvas\Social\Interactions\Models\EntityInteractions as ModelsEntityInteractions;
use Kanvas\Social\Interactions\Models\Interactions;
use Kanvas\Social\Interactions\Repositories\EntityInteractionsRepository;

class EntityInteractionMutation
{
    /**
     * Like a entity.
     */
    public function likeEntity(mixed $root, array $req): bool
    {
        return $this->handleInteractionEntity(
            $req,
            (string) StateEnums::LIKE->getValue(),
            (string) StateEnums::DISLIKE->getValue()
        );
    }

    /**
     * Like a entity.
     */
    public function unLikeEntity(mixed $root, array $req): bool
    {
        $likeEntityInput = LikeEntityInput::from($req['input']);
        $createEntityInteraction = new CreateEntityInteraction(
            $likeEntityInput,
            app(Apps::class)
        );

        return $createEntityInteraction->execute(
            (string) StateEnums::LIKE->getValue()
        )->softDelete();
    }

    /**
     * Like a entity.
     */
    public function disLikeEntity(mixed $root, array $req): bool
    {
        return $this->handleInteractionEntity(
            $req,
            (string) StateEnums::DISLIKE->getValue(),
            (string) StateEnums::LIKE->getValue()
        );
    }

    protected function handleInteractionEntity(
        array $req,
        string $interactionType,
        string $interactionTypeToDelete
    ): bool {
        $likeEntityInput = LikeEntityInput::from($req['input']);
        $createEntityInteraction = new CreateEntityInteraction(
            $likeEntityInput,
            app(Apps::class)
        );

        //cant like and dislike at the same time
        $likeInteraction = Interactions::getByName($interactionTypeToDelete, app(Apps::class));
        $likeEntityInteraction = EntityInteractionsRepository::getInteraction(
            $likeEntityInput,
            $likeInteraction
        );

        if ($likeEntityInteraction) {
            $likeEntityInteraction->softDelete();
        }

        return $createEntityInteraction->execute(
            $interactionType
        ) instanceof ModelsEntityInteractions;
    }

    /**
     * Given a like entity input get the social interactions for the entity.
     */
    public function getInteractionByEntity(mixed $root, array $req): array
    {
        return EntityInteractionsRepository::getEntityInteractions(
            LikeEntityInput::from($req['input'])
        );
    }
}
