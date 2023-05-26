<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Actions;

use Kanvas\Social\Follows\Models\UsersFollows;
use Kanvas\Social\Message\Models\UserMessage;
use Kanvas\Social\Messages\Models\Message;
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
        array $activity
    ) {
    }

    /**
     * execute
     *
     * @return void
     */
    public function execute()
    {
        $userMessage = UserMessage::firstOrCreate([
            'messages_id' => $this->message->id,
            'users_id' => $this->users->id,
        ]);

        $userMessageActivity = UserMessageActivity::firstOrCreate([
            'user_messages_id' => $userMessage->id,
            'from_entity_id' => $userMessage->appModuleMessage->entity_id,
            'entity_namespace' => $activity['entity_namespace'],
            'username' => $activity['username'],
            'type' => $activity['type'],
            'text' => $activity['text'],
        ]);
    }
}
