<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Actions;

use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\SystemModules\Models\SystemModules;

class CreateMessageAction
{
    public function __construct(
        public MessageInput $messageInput,
        public ?SystemModules $systemModule = null,
        public mixed $entityId = null,
    ) {
    }

    public function execute(): Message
    {
        $message = Message::create([
            'apps_id' => $this->messageInput->app->getId(),
            'parent_id' => $this->messageInput->parent_id,
            'parent_unique_id' => $this->messageInput->parent_unique_id,
            'companies_id' => $this->messageInput->company->getId(),
            'users_id' => $this->messageInput->user->getId(),
            'message_types_id' => $this->messageInput->type->getId(),
            'message' => $this->messageInput->message,
            'reactions_count' => $this->messageInput->reactions_count,
            'comments_count' => $this->messageInput->comments_count,
            'total_liked' => $this->messageInput->total_liked,
            'total_saved' => $this->messageInput->total_saved,
            'total_shared' => $this->messageInput->total_shared,
        ]);

        if ($this->systemModule) {
            $associateMessage = new AssociateMessageToSystemModule(
                $message,
                $this->systemModule,
                $this->entityId
            );
            $associateMessage->execute();
        }

        return $message;
    }
}
