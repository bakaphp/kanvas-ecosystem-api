<?php

declare(strict_types=1);

namespace Kanvas\Social\Channels\Actions;

use Illuminate\Support\Facades\Notification;
use Kanvas\Notifications\Channels\OneSignalNotificationChannel;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Notifications\NewMessageNotification;

class NotifiableUsersChannelAction
{
    public function __construct(
        public Channel $channel,
        public Message $message,
    ) {
    }

    public function execute(): void
    {
        $users = $this->channel->users()
            ->where('users.id', '!=', $this->message->users_id)
            ->get();
        $notification = new NewMessageNotification(
            message: $this->message,
            data: [
                'message' => $this->message->message,
                'channel' => $this->channel,
                'user' => $this->message->user,
            ],
            via: ['broadcast']
        );
        Notification::send($users, $notification);
    }
}
