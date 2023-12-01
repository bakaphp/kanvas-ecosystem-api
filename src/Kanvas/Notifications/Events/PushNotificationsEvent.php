<?php

namespace Kanvas\Notifications\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Kanvas\Notifications\Models\NotificationTypes;
use Kanvas\Users\Models\Users;
use Baka\Contracts\AppInterface;

class PushNotificationsEvent
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public Users $fromUser,
        public Users $toUser,
        public NotificationTypes $notificationType,
        public AppInterface $app,
        public array $message
    ) {
    }
}
