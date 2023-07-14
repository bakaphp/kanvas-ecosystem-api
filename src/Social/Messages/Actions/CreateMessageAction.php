<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Actions;

use Exception;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Repositories\MessageRepository;
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
        public int|string $entityId,
    ) {
    }

    /**
     * execute
     *
     * @return void
     */
    public function execute()
    {
        $message = MessageRepository::getByAppModuleMessage(
            $this->systemModule->model_name,
            $this->entityId
        );
        if ($message && $message->users_id = $this->messageInput->users_id) {
            $message->update([
                'parent_id' => $this->messageInput->parent_id,
                'message_types_id' => $this->messageInput->message_types_id,
                'message' => $this->messageInput->message,
            ]);

            return $message;
        } elseif ($message && $message->users_id != $this->messageInput->users_id) {
            throw new Exception('You are not allowed to update this message');
        }
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
