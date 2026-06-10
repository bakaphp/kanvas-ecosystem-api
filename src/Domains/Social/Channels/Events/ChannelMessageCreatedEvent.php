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

class ChannelMessageCreatedEvent implements ShouldBroadcast
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
    public function broadcastOn(): array
    {
        $channels = [
            new Channel('app-' . $this->channel->apps_id . '-new-message-channel-' . $this->channel->slug . '-' . $this->channel->id),
            new Channel('app-' . $this->channel->apps_id . $this->channel->slug),
        ];

        if (! empty($this->channel->entity_namespace)) {
            $entityNamespace = SystemModules::convertLegacySystemModules($this->channel->entity_namespace);
            $channelSlug = SystemModules::getSlugBySystemModuleNameSpace($entityNamespace);
            array_unshift(
                $channels,
                new Channel('new-message-channel-' . $channelSlug . '-' . ($this->channel->entity_id ?? $this->channel->id)),
            );
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'message.channel.added';
    }
}
