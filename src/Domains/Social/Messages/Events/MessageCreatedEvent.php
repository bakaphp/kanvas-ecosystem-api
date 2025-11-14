<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Kanvas\Social\Messages\Models\Message;
use Override;

class MessageCreatedEvent implements ShouldBroadcast
{
    use SerializesModels;
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        protected Message $message
    ) {
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->message->id,
            'slug' => $this->message->slug,
        ];
    }

    #[Override]
    public function broadcastOn(): Channel
    {
        return new Channel('new-message-' . $this->message->getId());
    }

    public function broadcastAs(): string
    {
        return 'message.added';
    }
}
