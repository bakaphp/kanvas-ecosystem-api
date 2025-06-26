<?php

namespace Kanvas\Souk\Orders\Actions;

use Exception;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderStatus;
use Kanvas\Workflow\Enums\WorkflowEnum;

class TransitionOrderStateAction
{
    public function __construct(
        protected Order $order,
        protected OrderStatus $newOrderStatus,
    ) {
    }

    public function execute(): array
    {
        $currentOrderStatus = $this->order->orderStatus;

        if (! $currentOrderStatus) {
            throw new Exception("Order status not found for order {$this->order->orderType->name}");
        }

        $orderStatusTransitions = $currentOrderStatus->fromTransitions()
            ->where('from_status_id', $currentOrderStatus->id)
            ->where('to_status_id', $this->newOrderStatus->id)
            ->first();

        if (! $orderStatusTransitions) {
            throw new Exception("The status {$this->newOrderStatus->name} is not a valid transition from {$currentOrderStatus->name}");
        }

        $this->order->order_status_id = $this->newOrderStatus->id;
        $this->order->save();

        $this->order->fireWorkflow(
            WorkflowEnum::STATUS_TRANSITION->value,
            true,
            [
                'app' => $this->order->app,
                'from_status' => $currentOrderStatus,
                'to_status' => $this->newOrderStatus,
            ]
        );

        return [
            'status' => 'success',
            'message' => 'Order status transitioned successfully',
        ];
    }
    
}