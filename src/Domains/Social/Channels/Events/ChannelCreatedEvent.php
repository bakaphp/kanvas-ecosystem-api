<?php

declare(strict_types=1);

namespace Kanvas\Social\Channels\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Kanvas\Social\Channels\Models\Channel as ModelsChannel;
use Override;

class ChannelCreatedEvent implements ShouldBroadcast
{
    use SerializesModels;
    use Dispatchable;
    use InteractsWithSockets;

    /** Latency is user-visible here — see ChannelMessageCreatedEvent for why this queue exists. */
    public string $broadcastQueue = 'broadcasts';

    public function __construct(
        protected ModelsChannel $channel
    ) {
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->channel->id,
            'slug' => $this->channel->slug,
            'uuid' => $this->channel->uuid,
            'name' => $this->channel->name,
            'entity_id' => $this->channel->entity_id,
            'companies_id' => $this->channel->companies_id,
        ];
    }

    #[Override]
    public function broadcastOn(): Channel
    {
        return new Channel('new-channel-' . $this->channel->entity_id . '-' . $this->channel->companies_id);
    }

    public function broadcastAs(): string
    {
        return 'channel.created';
    }
}
