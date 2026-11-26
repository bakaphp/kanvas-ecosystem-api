<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Kanvas\Connectors\Movipass\Enums\MovipassOrderStatusEnum;
use Kanvas\Connectors\Movipass\Support\WalkInOrder;
use Kanvas\Souk\Orders\Models\Order;

class ActivateEventBookingOrderAction
{
    public function __construct(
        protected readonly Order $order,
    ) {
    }

    public function execute(): bool
    {
        if (! $this->order->isEventBacked() || (float) $this->order->total_gross_amount > 0 || WalkInOrder::is($this->order)) {
            return false;
        }

        $this->order->transitionToStatus(
            $this->order->user,
            MovipassOrderStatusEnum::ACTIVE->value
        );

        return true;
    }
}
