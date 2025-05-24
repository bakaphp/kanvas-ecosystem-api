<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Actions;

use Illuminate\Support\Facades\DB;
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
        // Use shorter transaction with optimistic approach
        $messageId = $this->message->getId();
        $userId = $this->user->getId();
        $appsId = $this->message->apps_id;

        // First, try without transaction to reduce lock time
        $userMessage = UserMessage::withTrashed()
            ->where([
                'apps_id' => $appsId,
                'messages_id' => $messageId,
                'users_id' => $userId,
            ])
            ->first();

        if ($userMessage) {
            // Handle restoration in a minimal transaction
            if ($userMessage->trashed()) {
                DB::connection('social')->transaction(function () use ($userMessage) {
                    $userMessage->restore();
                });
            }
        } else {
            // Use updateOrCreate which handles race conditions gracefully
            $userMessage = UserMessage::updateOrCreate(
                [
                    'apps_id' => $appsId,
                    'messages_id' => $messageId,
                    'users_id' => $userId,
                ],
                [
                    'is_deleted' => 0,
                    'deleted_at' => null, // Ensure it's not soft deleted
                ]
            );
        }

        // Handle activity creation separately to minimize transaction scope
        if ($this->message->appModuleMessage && ! empty($this->activity)) {
            $this->createUserMessageActivity($userMessage);
        }

        return $userMessage;
    }

    private function createUserMessageActivity(UserMessage $userMessage): void
    {
        // Use updateOrCreate for activity as well
        UserMessageActivity::updateOrCreate(
            [
                'user_messages_id' => $userMessage->id,
                'from_entity_id' => $this->message->appModuleMessage->entity_id,
            ],
            [
                'entity_namespace' => $this->activity['entity_namespace'] ?? null,
                'username' => $this->activity['username'] ?? null,
                'type' => $this->activity['type'] ?? null,
                'text' => $this->activity['text'] ?? null,
            ]
        );
    }
}
