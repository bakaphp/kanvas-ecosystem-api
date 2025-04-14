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
        $createNuggetMessage = new CreateMessageAction(
            messageInput: MessageInput::fromArray(
            [
                'parent_id' => $this->parentMessage->getId(),
                'parent_unique_id' => $this->parentMessage->getUniqueId(),
                'message' => $this->messageData,
                'is_public' => 1,
            ],
                $this->parentMessage->user,
                MessagesTypesRepository::getByVerb('memo', $this->parentMessage->app->getId()),
                $this->parentMessage->company,
                $this->parentMessage->app,
            )
        );

        $nuggetMessage = $createNuggetMessage->execute();

        DB::connection('social')->table('messages')
            ->where('id', $nuggetMessage->getId())
            ->update(['path' => $this->parentMessage->getId() . "." . $nuggetMessage->getId()]);

        foreach ($this->parentMessage->tags() as $tag) {
            DB::connection('social')->table('tags_entities')->insert([
                'entity_id' => $nuggetMessage->getId(),
                'tags_id' => $tag->getId(),
                'users_id' => $this->parentMessage->users_id,
                'taggable_type' => Message::class,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }

        // Update total children on parent message
        $this->parentMessage->total_children++;
        $this->parentMessage->save();
        return $nuggetMessage;
    }
}
