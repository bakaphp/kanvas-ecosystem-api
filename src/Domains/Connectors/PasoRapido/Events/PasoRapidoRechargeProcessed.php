<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PasoRapido\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;
use Override;

class PasoRapidoRechargeProcessed implements ShouldBroadcast, ShouldRescue
{
    use Dispatchable;
    use InteractsWithSockets;

    public const string SUCCESS = 'success';

    public const string FAILED = 'failed';

    public function __construct(
        public readonly int $orderId,
        public readonly string $orderUuid,
        public readonly string $status,
        public readonly string $message,
    ) {
    }

    public function broadcastWith(): array
    {
        return [
            'order_id' => $this->orderId,
            'status' => $this->status,
            'message' => $this->message,
        ];
    }

    #[Override]
    public function broadcastOn(): Channel
    {
        return new Channel('order-' . $this->orderUuid);
    }

    public function broadcastAs(): string
    {
        return 'paso_rapido.recharge';
    }
}
