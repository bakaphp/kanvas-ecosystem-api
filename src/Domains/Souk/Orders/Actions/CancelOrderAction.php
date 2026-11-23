<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Actions;

use Kanvas\Souk\Orders\Enums\OrderStatusEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;

class CancelOrderAction
{
    public const string CANCELLED_STATUS_SLUG = 'cancelled';

    public function __construct(
        protected readonly Order $order,
        protected readonly Users $user,
    ) {
    }

    public function execute(): Order
    {
        if (in_array($this->order->status, OrderStatusEnum::closedValues(), true)) {
            return $this->order;
        }

        $this->order->transitionToStatus($this->user, self::CANCELLED_STATUS_SLUG);

        $this->order->status = OrderStatusEnum::CANCELED->value;
        $this->order->saveQuietly();

        return $this->order;
    }
}
