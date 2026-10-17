<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Kanvas\Social\Channels\Events\ChannelMessageCreatedEvent;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Override;

class MessageDeliveryStatusEvent implements ShouldBroadcast
{
    use SerializesModels;
    use Dispatchable;
    use InteractsWithSockets;

    /** Latency is user-visible here — see ChannelMessageCreatedEvent for why this queue exists. */
    public string $broadcastQueue = 'broadcasts';

    public function __construct(
        public Channel $channel,
        public Message $message,
        public array $delivery,
    ) {
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->message->id,
            'slug' => $this->message->slug,
            'channel_id' => $this->channel->id,
            'channel_slug' => $this->channel->slug,
            'delivery' => $this->delivery,
        ];
    }

    #[Override]
    public function broadcastOn(): array
    {
        return ChannelMessageCreatedEvent::pusherChannelsFor($this->channel);
    }

    public function broadcastAs(): string
    {
        return 'message.delivery.updated';
    }
}
