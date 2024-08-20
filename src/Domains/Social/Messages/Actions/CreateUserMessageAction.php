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
    /**
     * __construct
     *
     * @return void
     */
    public function __construct(
        public Message $message,
        public Users|UsersFollows $user,
        public array $activity
    ) {
    }

    /**
     * execute
     */
    public function execute(): UserMessage
    {
        $userMessage = UserMessage::firstOrCreate([
            'messages_id' => $this->message->getId(),
            'users_id' => $this->user->getId(),
        ]);

        if ($this->message->appModuleMessage) {
            UserMessageActivity::firstOrCreate([
                 'user_messages_id' => $userMessage->id,
                 'from_entity_id' => $this->message->appModuleMessage->entity_id,
                 'entity_namespace' => $this->activity['entity_namespace'],
                 'username' => $this->activity['username'],
                 'type' => $this->activity['type'],
                 'text' => $this->activity['text'],
             ]);
        }

        return $userMessage;
    }
}
