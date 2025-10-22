<?php

declare(strict_types=1);

namespace Kanvas\Social\Channels\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Kanvas\Social\Channels\Models\Channel as ModelsChannel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\SystemModules\Models\SystemModules;
use Override;

class MessageAddedToChannelEvent implements ShouldBroadcast
{
    use SerializesModels;
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        protected ModelsChannel $channel,
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
        $channelSlug = SystemModules::getSlugBySystemModuleNameSpace($this->channel->entity_namespace);

        return new Channel('new-message-channel-' . $channelSlug . '-' . ($this->channel->entity_id ?? $this->channel->id));
    }

    public function broadcastAs(): string
    {
        return 'message.channel.added';
    }
}
