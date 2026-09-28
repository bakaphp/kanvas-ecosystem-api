<?php

declare(strict_types=1);

namespace Kanvas\Social\Channels\Events;

use Baka\Support\Str;
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

    /**
     * Off `default` (shared with imports/webhooks) so chat isn't delayed behind a backlog, and off
     * `agent-chat` because that worker runs at --tries=1 for multi-minute turns while an idempotent
     * push wants --tries=3 — retry policy is a worker flag, so they cannot share a queue.
     */
    public string $broadcastQueue = 'broadcasts';

    public function __construct(
        protected ModelsChannel $channel,
        protected Message $message
    ) {
    }

    /**
     * An id-only handle, never the body: Pusher caps an event at ~10KB and would drop a large
     * payload exactly when it matters. Clients fetch by `id`, which is committed before this fires.
     * `channel_id` is needed because two of the channels below are app- or slug-scoped.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->message->id,
            'slug' => $this->message->slug,
            'channel_id' => $this->channel->id,
            'channel_slug' => $this->channel->slug,
        ];
    }

    #[Override]
    public function broadcastOn(): array
    {
        $appPrefix = 'app-' . $this->channel->apps_id;

        $channels = [
            new Channel(Str::sanitizeChannelName($appPrefix . '-new-message-channel-' . $this->channel->slug . '-' . $this->channel->id)),
            new Channel(Str::sanitizeChannelName($appPrefix . $this->channel->slug)),
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
