<?php

declare(strict_types=1);

namespace App\GraphQL\Social\Mutations\Interactions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Enums\InteractionEnum;
use Kanvas\Social\Interactions\Actions\CreateEntityInteraction;
use Kanvas\Social\Interactions\Actions\CreateInteraction;
use Kanvas\Social\Interactions\DataTransferObject\Interaction;
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
            (string) InteractionEnum::LIKE->getValue(),
            (string) InteractionEnum::DISLIKE->getValue()
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
            (string) InteractionEnum::LIKE->getValue()
        )->softDelete();
    }

    /**
     * Like a entity.
     */
    public function disLikeEntity(mixed $root, array $req): bool
    {
        return $this->handleInteractionEntity(
            $req,
            (string) InteractionEnum::DISLIKE->getValue(),
            (string) InteractionEnum::LIKE->getValue()
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
        $interactionTypeEntity = (new CreateInteraction(
            new Interaction(
                $interactionTypeToDelete,
                app(Apps::class),
                ucfirst($interactionTypeToDelete),
            )
        ))->execute();
        $interactionEntityToDelete = EntityInteractionsRepository::getInteraction(
            $likeEntityInput,
            $interactionTypeEntity
        );

        if ($interactionEntityToDelete) {
            $interactionEntityToDelete->softDelete();
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
