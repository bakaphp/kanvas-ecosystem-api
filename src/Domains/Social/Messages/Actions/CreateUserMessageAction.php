<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Actions;

use Kanvas\Social\Follows\Models\UsersFollows;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Models\UserMessage;
use Kanvas\Social\Messages\Models\UserMessageActivity;
use Kanvas\Users\Models\Users;

class CreateUserMessageAction
{
    public function __construct(
        public Message $message,
        public Users|UsersFollows $user,
        public array $activity
    ) {
        if ($user instanceof UsersFollows) {
            $this->user = $user->user;
        }
    }

    public function execute(): UserMessage
    {
        $userMessage = UserMessage::firstOrCreate([
            'messages_id' => $this->message->getId(),
            'users_id' => $this->user->getId(),
            'apps_id' => $this->message->apps_id,
        ]);

        if ($this->message->appModuleMessage && ! empty($this->activity)) {
            UserMessageActivity::firstOrCreate([
                 'user_messages_id' => $userMessage->id,
                 'from_entity_id' => $this->message->appModuleMessage->entity_id,
                 'entity_namespace' => $this->activity['entity_namespace'] ?? null,
                 'username' => $this->activity['username'] ?? null,
                 'type' => $this->activity['type'] ?? null,
                 'text' => $this->activity['text'] ?? null,
             ]);
        }

        return $userMessage;
    }
}
