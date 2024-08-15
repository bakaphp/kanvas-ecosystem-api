<?php

declare(strict_types=1);

namespace App\GraphQL\Social\Mutations\Messages;

use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Enums\InteractionEnum;
use Kanvas\Social\Interactions\Actions\CreateInteraction;
use Kanvas\Social\Interactions\DataTransferObject\Interaction;
use Kanvas\Social\Interactions\Models\Interactions;
use Kanvas\Social\Interactions\Models\UsersInteractions;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\Enums\ActivityTypeEnum;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Services\MessageInteractionService;

class MessageInteractionMutation
{
    public function interaction(mixed $root, array $request): Message
    {
        $message = Message::getById((int)$request['id'], app(Apps::class));
        $action = new CreateMessageAction(
            $message,
            auth()->user(),
            ActivityTypeEnum::from($request['type'])
        );
        $action->execute();

        return $message;
    }

    public function like(mixed $root, array $request): bool
    {
        $user = auth()->user();
        $message = Message::getById((int)$request['id'], app(Apps::class));

        $messageInteractionService = new MessageInteractionService($message);
        $messageInteractionService->like($user);

        return $user->like($message) instanceof UsersInteractions;
    }

    public function dislike(mixed $root, array $request): bool
    {
        $user = auth()->user();
        $message = Message::getById((int)$request['id'], app(Apps::class));

        $messageInteractionService = new MessageInteractionService($message);
        $messageInteractionService->dislike($user);

        return $user->like($message, true) instanceof UsersInteractions;
    }

    public function share(mixed $root, array $request): string
    {
        $message = Message::getById((int)$request['id'], app(Apps::class));
        $this->createInteraction(InteractionEnum::SHARE->getValue());

        $messageInteractionService = new MessageInteractionService($message);

        return $messageInteractionService->share(auth()->user());
    }

    public function view(mixed $root, array $request): int
    {
        $user = auth()->user();
        $message = Message::getById((int)$request['id'], app(Apps::class));
        $this->createInteraction(InteractionEnum::VIEW->getValue());

        $messageInteractionService = new MessageInteractionService($message);
        $messageInteractionService->view($user);

        return $message->total_view + 1;
    }

    protected function createInteraction(string $interactionType): Interactions
    {
        $interaction = new CreateInteraction(
            new Interaction(
                $interactionType,
                app(Apps::class),
                ucfirst($interactionType),
            )
        );

        return $interaction->execute();
    }
}
