<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Listeners;

use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Social\Messages\Events\MessageMentionsStoredEvent;
use Kanvas\Social\Messages\Notifications\UserMentionedNotification;
use Kanvas\Users\Models\Users;

class NotifyMentionedUsersListener
{
    public function handle(MessageMentionsStoredEvent $event): void
    {
        $message = $event->message;
        $authorId = (int) $message->users_id;

        foreach ($event->mentionedUserIds as $userId) {
            $userId = (int) $userId;

            // Don't notify the author for mentioning themselves.
            if ($userId === $authorId) {
                continue;
            }

            // Agent users are driven by RespondToAgentMentionListener (a wake), not a notification.
            if (Agent::fromUser($userId, $message->app, $message->company) !== null) {
                continue;
            }

            $user = Users::getById($userId);

            $user->notify(new UserMentionedNotification($message, $message->user));
        }
    }
}
