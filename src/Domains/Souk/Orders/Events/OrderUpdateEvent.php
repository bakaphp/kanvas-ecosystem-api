<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Kanvas\Souk\Orders\Models\Order;
use Override;

class OrderUpdateEvent implements ShouldBroadcast
{
    use SerializesModels;
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(protected Order $order)
    {
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->order->id,
            'user' => $this->order->user->name,
        ];
    }

    #[Override]
    public function broadcastOn(): Channel
    {
        return new Channel('order-' . $this->order->uuid);
    }

    public function broadcastAs(): string
    {
        return 'order.updated';
    }
}
