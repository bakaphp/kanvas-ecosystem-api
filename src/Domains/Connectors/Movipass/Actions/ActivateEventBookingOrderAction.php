<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Kanvas\Connectors\Movipass\Enums\MovipassOrderStatusEnum;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Event\Passes\DataTransferObject\WalkInAccess;
use Kanvas\Souk\Orders\Models\Order;

class ActivateEventBookingOrderAction
{
    public function __construct(
        protected readonly Order $order,
    ) {
    }

    public function execute(): bool
    {
        if (! $this->order->isEventBacked() || (float) $this->order->total_gross_amount > 0 || $this->isWalkIn()) {
            return false;
        }

        $this->order->transitionToStatus(
            $this->order->user,
            MovipassOrderStatusEnum::ACTIVE->value
        );

        return true;
    }

    private function isWalkIn(): bool
    {
        $event = Event::find($this->order->resources_id);

        return WalkInAccess::forResource($event?->resource) !== null;
    }
}
