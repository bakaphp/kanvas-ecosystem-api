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
use Kanvas\Social\Messages\Models\Message;

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

        $shareUrl = $this->message->app->get(AppEnum::SHAREABLE_LINK->value) ?? $this->message->app->url;

        return $shareUrl . '/' . (! empty($this->message->slug) ? $this->message->slug : $this->message->getId());
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
}
