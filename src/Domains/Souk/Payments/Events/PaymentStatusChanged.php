<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Override;

class PaymentStatusChanged implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        public readonly int $paymentId,
        public readonly string $status,
        public readonly int $orderId,
        public readonly string $orderUuid,
    ) {
    }

    public function broadcastWith(): array
    {
        return [
            'payment_id' => $this->paymentId,
            'status' => $this->status,
            'order_id' => $this->orderId,
        ];
    }

    #[Override]
    public function broadcastOn(): Channel
    {
        return new Channel('order-' . $this->orderUuid);
    }

    public function broadcastAs(): string
    {
        return 'payment.status_changed';
    }
}
