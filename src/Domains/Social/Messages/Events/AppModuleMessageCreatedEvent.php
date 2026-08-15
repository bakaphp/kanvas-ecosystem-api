<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Kanvas\Social\Messages\Models\AppModuleMessage;
use Override;

class AppModuleMessageCreatedEvent implements ShouldBroadcast
{
    use SerializesModels;
    use Dispatchable;
    use InteractsWithSockets;

    /** Latency is user-visible here — see ChannelMessageCreatedEvent for why this queue exists. */
    public string $broadcastQueue = 'broadcasts';

    public function __construct(
        public AppModuleMessage $appModuleMessage,
    ) {
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->appModuleMessage->id,
            'message_id' => $this->appModuleMessage->message_id,
            'system_modules' => $this->appModuleMessage->system_modules,
            'entity_id' => $this->appModuleMessage->entity_id,
            'apps_id' => $this->appModuleMessage->apps_id,
            'companies_id' => $this->appModuleMessage->companies_id,
        ];
    }

    #[Override]
    public function broadcastOn(): array
    {
        return [
            new Channel('messages-' . $this->appModuleMessage->entity_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'app-module-message.created';
    }
}
