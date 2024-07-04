<?php

declare(strict_types=1);

namespace App\GraphQL\Social\Mutations\UsersInteractions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Enums\InteractionEnum;
use Kanvas\Social\Interactions\Actions\CreateInteraction;
use Kanvas\Social\Interactions\DataTransferObject\Interaction;
use Kanvas\Social\UsersInteractions\Actions\CreateUserInteractionAction;
use Kanvas\Social\UsersInteractions\DataTransferObject\UserInteraction;
use Kanvas\Social\UsersInteractions\Models\UserInteraction as UserInteractionModel;

class UsersInteractionsManagement
{
    public function like($__, array $request): bool
    {
        return $this->likeEntity($request) instanceof UserInteractionModel;
    }

    public function unLike($__, array $request): bool
    {
        return $this->likeEntity($request)->softDelete();
    }

    public function disLike($__, array $request): bool
    {
        $interactionType = (string) InteractionEnum::DISLIKE->getValue();
        $createInteractions = new CreateInteraction(
            new Interaction(
                $interactionType,
                app(Apps::class),
                ucfirst($interactionType),
            )
        );
        $interaction = $createInteractions->execute();
        $request['input']['interaction'] = $interaction;
        $request['input']['user'] = auth()->user();

        $data = UserInteraction::from($request['input']);
        $createUserInteraction = new CreateUserInteractionAction($data);
        $userInteraction = $createUserInteraction->execute();

        return $userInteraction instanceof UserInteractionModel;
    }

    protected function likeEntity(array $request): UserInteractionModel
    {
        $interactionType = (string) InteractionEnum::LIKE->getValue();
        $createInteractions = new CreateInteraction(
            new Interaction(
                $interactionType,
                app(Apps::class),
                ucfirst($interactionType),
            )
        );
        $interaction = $createInteractions->execute();
        $request['input']['interaction'] = $interaction;
        $request['input']['user'] = auth()->user();

        $data = UserInteraction::from($request['input']);
        $createUserInteraction = new CreateUserInteractionAction($data);
        $userInteraction = $createUserInteraction->execute();

        return $userInteraction;
    }
}
