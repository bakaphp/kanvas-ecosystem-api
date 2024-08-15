<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Actions;

use Illuminate\Support\Facades\Validator;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Validations\ValidParentMessage;
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
        $data = [
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
            'total_disliked' => $this->messageInput->total_disliked,
            'total_saved' => $this->messageInput->total_saved,
            'total_shared' => $this->messageInput->total_shared,
        ];

        $validator = Validator::make($data, [
            'parent_id' => [new ValidParentMessage($this->messageInput->app->getId())],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator->messages()->__toString());
        }

        if ($this->messageInput->parent_id == null || $this->messageInput->parent_id == 0) {
            $data['parent_id'] = null;
        }

        $message = Message::create($data);

        if ($this->systemModule && $this->entityId !== null) {
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
