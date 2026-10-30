<?php

declare(strict_types=1);

namespace Kanvas\Social\Channels\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Override;

/**
 * Scalars only, never the models: a deleted message is hidden by the `is_deleted = 0` global scope,
 * so `SerializesModels` could not re-hydrate it on the queue worker. Dispatched after commit because
 * the edit runs inside a transaction and a client re-reading early would see the old text.
 */
abstract class ChannelMessageChangedEvent implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;
    use InteractsWithSockets;

    public string $broadcastQueue = 'broadcasts';

    public function __construct(
        protected int $messageId,
        protected ?string $messageSlug,
        protected int $channelId,
        protected string $channelSlug,
        protected int $appsId,
        protected ?string $entityNamespace,
        protected int|string|null $entityId,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->messageId,
            'slug' => $this->messageSlug,
            'channel_id' => $this->channelId,
            'channel_slug' => $this->channelSlug,
        ];
    }

    #[Override]
    public function broadcastOn(): array
    {
        return ChannelMessageCreatedEvent::pusherChannelNames(
            $this->appsId,
            $this->channelId,
            $this->channelSlug,
            $this->entityNamespace,
            $this->entityId,
        );
    }
}
