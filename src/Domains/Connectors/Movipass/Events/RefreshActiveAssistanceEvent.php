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

class RefreshActiveAssistanceEvent implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        protected Order $order,
        protected int $mechanicId,
    ) {
    }

    public function broadcastWith(): array
    {
        return [
            'orderId' => $this->order->getId(),
        ];
    }

    /**
     * @return Channel[]
     */
    #[Override]
    public function broadcastOn(): array
    {
        return [
            new Channel('app-' . $this->order->apps_id . '-user-channel-' . $this->mechanicId),
            new Channel('app-' . $this->order->apps_id . '-user-channel-' . $this->order->users_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'refresh.active.assistance';
    }
}
