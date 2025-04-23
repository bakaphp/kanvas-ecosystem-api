<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PromptMine\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Repositories\MessagesTypesRepository;

class CreateNuggetMessageAction
{
    public function __construct(
        private Message $parentMessage,
        private array $messageData = [],
    ) {
    }

    public function execute(): Message
    {
        $messageTypeValue = $this->messageData['type'] == 'text-format' ? 'nugget' : 'image';
        $nuggetMessage = Message::on('social')->create([
            'parent_id'        => $this->parentMessage->getId(),
            'apps_id'          => $this->parentMessage->apps_id,
            'uuid'             => DB::raw('uuid()'),
            'companies_id'     => $this->parentMessage->companies_id,
            'users_id'         => $this->parentMessage->users_id,
            'message_types_id' => MessagesTypesRepository::getByVerb('memo', $this->parentMessage->app)->getId(),
            'message'          => [
                'title'           => $this->messageData['title'],
                'type'            => $this->messageData['type'],
                $messageTypeValue => $this->messageData[$messageTypeValue],
            ],
            'is_public'  => $this->messageData['is_public'] ?? 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $nuggetMessage->addTags($this->parentMessage->tags->pluck('name')->toArray());
        $this->parentMessage->total_children++;
        $this->parentMessage->save();

        return $nuggetMessage;
    }
}
