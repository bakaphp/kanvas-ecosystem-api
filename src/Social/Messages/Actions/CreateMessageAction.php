<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Actions;

use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\SystemModules\Models\SystemModules;

class CreateMessageAction
{
    /**
     * __construct
     *
     * @return void
     */
    public function __construct(
        public MessageInput $messageInput,
        public SystemModules $systemModule,
        public string $entityId,
    ) {
    }

    /**
     * execute
     *
     * @return void
     */
    public function execute()
    {
        $message = Message::create([
            'apps_id' => $this->messageInput->apps_id,
            'parent_id' => $this->messageInput->parent_id,
            'parent_unique_id' => $this->messageInput->parent_unique_id,
            'companies_id' => $this->messageInput->companies_id,
            'users_id' => $this->messageInput->users_id,
            'message_types_id' => $this->messageInput->message_types_id,
            'message' => $this->messageInput->message,
            'reactions_count' => $this->messageInput->reactions_count,
            'comments_count' => $this->messageInput->comments_count,
            'total_liked' => $this->messageInput->total_liked,
            'total_saved' => $this->messageInput->total_saved,
            'total_shared' => $this->messageInput->total_shared,
        ]);

        $associateMessage = new AssociateMessageToSystemModule(
            $message,
            $this->systemModule,
            $this->entityId
        );
        $associateMessage->execute();

        return $message;
    }
}
