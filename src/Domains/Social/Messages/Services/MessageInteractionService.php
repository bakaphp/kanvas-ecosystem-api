<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Services;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Social\Enums\AppEnum;
use Kanvas\Social\Enums\InteractionEnum;
use Kanvas\Social\Interactions\Actions\CreateUserInteractionAction;
use Kanvas\Social\Interactions\DataTransferObject\UserInteraction;
use Kanvas\Social\Interactions\Models\Interactions;
use Kanvas\Social\Interactions\Models\UsersInteractions;
use Kanvas\Social\Messages\Actions\CreateUserMessageAction;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Models\UserMessage;

class MessageInteractionService
{
    public function __construct(
        protected Message $message
    ) {
    }

    public function share(UserInterface $who, ?UserInterface $to = null): string
    {
        $this->incrementInteractionCount('total_shared');
        $this->createInteraction($who, InteractionEnum::SHARE->getValue());

        $userMessage = $this->addToUserMessage($who);
        $userMessage->is_shared = 1;
        $userMessage->saveOrFail();

        $shareUrl = $this->message->app->get(AppEnum::SHAREABLE_LINK->value) ?? $this->message->app->url;

        if ($this->message->app->get(AppEnum::SHAREABLE_LINK_WITH_USERNAME->value)) {
            $shareUrl .= '/' . $this->message->user->displayname;
        }

        return $shareUrl . '/' . (! empty($this->message->slug) ? $this->message->slug : $this->message->getId());
    }

    public function view(UserInterface $who): UsersInteractions
    {
        $this->incrementInteractionCount('total_view');

        return $this->createInteraction($who, InteractionEnum::VIEW->getValue());
    }

    public function like(UserInterface $who): UsersInteractions
    {
        $this->incrementInteractionCount('total_liked');

        $userInteraction = $this->createInteraction($who, InteractionEnum::LIKE->getValue());

        $userMessage = $this->addToUserMessage($who);
        $userMessage->is_liked = 1;
        $userMessage->saveOrFail();

        return $userInteraction;
    }

    public function dislike(UserInterface $who): UsersInteractions
    {
        $this->incrementInteractionCount('total_disliked');

        $userInteraction = $this->createInteraction($who, InteractionEnum::DISLIKE->getValue());

        $userMessage = $this->addToUserMessage($who);
        $userMessage->is_disliked = 1;
        $userMessage->is_liked = 0;
        $userMessage->saveOrFail();

        return $userInteraction;
    }

    protected function incrementInteractionCount(string $interactionType): void
    {
        $this->message->$interactionType++;
        $this->message->saveOrFail();
    }

    protected function createInteraction(UserInterface $who, string $interactionType, ?string $note = null): UsersInteractions
    {
        $interaction = Interactions::getByName($interactionType, $this->message->app);
        $createUserInteraction = new CreateUserInteractionAction(
            new UserInteraction(
                $who,
                $interaction,
                (string)$this->message->getId(),
                Message::class,
                $note
            )
        );

        return $createUserInteraction->execute();
    }

    protected function addToUserMessage(UserInterface $user): UserMessage
    {
        $createUserMessage = new CreateUserMessageAction(
            $this->message,
            $user,
            [
            ]
        );

        return $createUserMessage->execute();
    }
}
