<?php

declare(strict_types=1);

namespace App\GraphQL\Social\Mutations\UsersInteractions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Enums\AppEnum;
use Kanvas\Social\Enums\InteractionEnum;
use Kanvas\Social\Interactions\Actions\CreateInteraction;
use Kanvas\Social\Interactions\Actions\CreateUserInteractionAction;
use Kanvas\Social\Interactions\DataTransferObject\Interaction;
use Kanvas\Social\Interactions\DataTransferObject\UserInteraction;
use Kanvas\Social\Interactions\Models\Interactions;
use Kanvas\Social\Interactions\Models\UsersInteractions;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;

class UsersInteractionsManagement
{
    public function like($__, array $request): bool
    {
        return $this->likeEntity($request) instanceof UsersInteractions;
    }

    public function unLike($__, array $request): bool
    {
        return $this->likeEntity($request)->softDelete();
    }

    public function shareUser($__, array $request): string
    {
        $userId = $request['id'];
        $app = app(Apps::class);
        $who = auth()->user();
        $interactionType = (string) InteractionEnum::SHARE->getValue();
        $sharedUser = Users::getById($userId);

        UsersRepository::belongsToThisApp($sharedUser, $app);

        $interaction = Interactions::getByName($interactionType, $app);
        $createUserInteraction = new CreateUserInteractionAction(
            new UserInteraction(
                $who,
                $interaction,
                $userId,
                Users::class,
            )
        );

        $createUserInteraction->execute();

        $shareUrl = $app->get(AppEnum::SHAREABLE_LINK->value);
        $shareUrl .= '/' . $sharedUser->getAppProfile($app)->displayname;

        return $shareUrl;
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

        return $userInteraction instanceof UsersInteractions;
    }

    protected function likeEntity(array $request): UsersInteractions
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
