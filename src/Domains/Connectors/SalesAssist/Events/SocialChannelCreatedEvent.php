<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Events;

use Baka\Contracts\AppInterface;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Override;

class SocialChannelCreatedEvent implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    public $connection = 'sync';

    public function __construct(
        protected AppInterface $app,
        protected string $channelType,
        protected int $sessionId,
        protected string $channelSlug,
        protected string $channelName,
        protected int $peopleId,
        protected int $leadId,
    ) {
    }

    public function broadcastWith(): array
    {
        return [
            'channel_type' => $this->channelType,
            'session_id' => $this->sessionId,
            'channel_slug' => $this->channelSlug,
            'channel_name' => $this->channelName,
            'people_id' => $this->peopleId,
            'lead_id' => $this->leadId,
        ];
    }

    #[Override]
    public function broadcastOn(): Channel
    {
        return new Channel('app-' . $this->app->getId() . '-social-channel-' . $this->leadId);
    }

    public function broadcastQueue(): string
    {
        return 'default';
    }
}
