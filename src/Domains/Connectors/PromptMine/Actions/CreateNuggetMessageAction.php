<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PromptMine\Actions;

use Kanvas\Social\Messages\Models\Message;
use Illuminate\Support\Facades\DB;
use Kanvas\Social\MessagesTypes\Repositories\MessagesTypesRepository;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;

class CreateNuggetMessageAction
{
    public function __construct(
        private Message $parentMessage,
        private array $messageData = [],
    ) {
    }

    public function execute(): Message
    {
        $nuggetMessage = (new CreateMessageAction(
            messageInput: MessageInput::fromArray(
                [
                    'parent_id' => $this->parentMessage->getId(),
                    'parent_unique_id' => $this->parentMessage->getUniqueId(),
                    'message' => $this->messageData,
                    'is_public' => 1,
                ],
                $this->parentMessage->user,
                MessagesTypesRepository::getByVerb('memo', $this->parentMessage->app),
                $this->parentMessage->company,
                $this->parentMessage->app,
            )
        ))->execute();

        $nuggetMessage->addTags($this->parentMessage->tags->pluck('name'));
        $this->parentMessage->total_children++;
        $this->parentMessage->save();
        return $nuggetMessage;
    }
}
