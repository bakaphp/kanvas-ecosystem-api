<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Services;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Social\Enums\AppEnum;
use Kanvas\Social\Enums\InteractionEnum;
use Kanvas\Social\Interactions\Actions\CreateInteraction;
use Kanvas\Social\Interactions\Actions\CreateUserInteractionAction;
use Kanvas\Social\Interactions\DataTransferObject\Interaction;
use Kanvas\Social\Interactions\DataTransferObject\UserInteraction;
use Kanvas\Social\Interactions\Models\Interactions;
use Kanvas\Social\Interactions\Models\UsersInteractions;
use Kanvas\Social\Messages\Actions\CreateUserMessageAction;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Models\UserMessage;
use Kanvas\Workflow\Enums\WorkflowEnum;

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
        $userInteraction = $this->createInteraction($who, InteractionEnum::LIKE->getValue());

        $userMessage = $this->addToUserMessage($who);
        $userMessage->is_liked = $userMessage->is_liked == 1 ? 0 : 1; //turn off the like if it was already liked
        $userMessage->is_disliked = 0;
        $userMessage->saveOrFail();

        if ($userMessage->is_liked == 1) {
            $this->incrementInteractionCount('total_liked');
        } else {
            $this->decrementInteractionCount('total_liked');
        }

        return $userInteraction;
    }

    public function dislike(UserInterface $who): UsersInteractions
    {
        $userInteraction = $this->createInteraction($who, InteractionEnum::DISLIKE->getValue());

        $userMessage = $this->addToUserMessage($who);
        $userMessage->is_disliked = $userMessage->is_disliked == 1 ? 0 : 1; //turn off the dislike if it was already disliked
        $userMessage->is_liked = 0;
        $userMessage->saveOrFail();

        if ($userMessage->is_disliked == 1) {
            $this->incrementInteractionCount('total_disliked');
        } else {
            $this->decrementInteractionCount('total_disliked');
        }

        return $userInteraction;
    }

    public function purchase(UserInterface $who): UsersInteractions
    {
        $this->incrementInteractionCount('total_purchased');

        $userInteraction = $this->createInteraction($who, InteractionEnum::PURCHASE->getValue());
        $userMessage = $this->addToUserMessage($who);
        $userMessage->is_purchased = 1;
        $userMessage->saveOrFail();

        return $userInteraction;
    }

    protected function incrementInteractionCount(string $interactionType): void
    {
        $this->message->$interactionType++;
        $this->message->saveOrFail();
    }

    protected function decrementInteractionCount(string $interactionType): void
    {
        $this->message->$interactionType--;
        $this->message->saveOrFail();
    }

    protected function createInteraction(UserInterface $who, string $interactionType, ?string $note = null): UsersInteractions
    {
        //$interaction = Interactions::getByName($interactionType, $this->message->app);
        $interaction = (new CreateInteraction(
            new Interaction(
                $interactionType,
                $this->message->app,
                $interactionType,
            )
        ))->execute();
        $createUserInteraction = new CreateUserInteractionAction(
            new UserInteraction(
                $who,
                $interaction,
                (string)$this->message->getId(),
                Message::class,
                $note
            )
        );

        $userInteraction = $createUserInteraction->execute();

        $this->message->fireWorkflow(
            event: WorkflowEnum::AFTER_MESSAGE_INTERACTION->value,
            async: true,
            params: [
                'interaction' => $interactionType,
                'user_interaction' => $userInteraction,
            ]
        );

        return $userInteraction;
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
