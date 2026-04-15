<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Kanvas\Souk\Orders\Models\Order;
use Override;

class AssistanceRequestedEvent implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(protected Order $order)
    {
    }

    public function broadcastWith(): array
    {
        return [
            'orderId' => $this->order->getId(),
        ];
    }

    #[Override]
    public function broadcastOn(): Channel
    {
        return new Channel('app-' . $this->order->apps_id . '-assistance-operators-channel');
    }

    public function broadcastAs(): string
    {
        return 'assistance.requested';
    }
}
