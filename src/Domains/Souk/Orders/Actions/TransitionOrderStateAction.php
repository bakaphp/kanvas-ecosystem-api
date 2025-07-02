<?php

namespace Kanvas\Souk\Orders\Actions;

use Exception;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderStatus;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Enums\WorkflowEnum;

class TransitionOrderStateAction
{
    public function __construct(
        protected Order $order,
        protected OrderStatus $newOrderStatus,
        protected Users $user,
    ) {
    }

    public function execute(bool $processQuietly = false): array
    {
        $currentOrderStatus = $this->order->orderStatus;

        if (! $currentOrderStatus) {
            if ($processQuietly) {
                return [
                    'status' => 'error',
                    'message' => "Order status not found for order {$this->order->orderType->name}",
                ];
            }

            throw new Exception("Order status not found for order {$this->order->orderType->name}");
        }

        $orderStatusTransitions = $currentOrderStatus->fromTransitions()
            ->where('from_status_id', $currentOrderStatus->id)
            ->where('to_status_id', $this->newOrderStatus->id)
            ->first();

        if (! $orderStatusTransitions) {
            if ($processQuietly) {
                return [
                    'status' => 'error',
                    'message' => "The status {$this->newOrderStatus->name} is not a valid transition from {$currentOrderStatus->name}",
                ];
            }

            throw new Exception("The status {$this->newOrderStatus->name} is not a valid transition from {$currentOrderStatus->name}");
        }

        $log = activity('change-order-status')
           ->causedBy($this->user)
           ->withProperties([
               'order' => $this->order->toArray(),
               'new_order_status' => $this->newOrderStatus->toArray(),
           ])
           ->log('User changed order status');

        $this->order->updateQuietly(['order_status_id' => $this->newOrderStatus->id]);

        $log->subject_type = get_class($this->order);
        $log->subject_id = $this->order->id;
        $log->description = 'User successfully changed order status';
        $log->saveOrFail();

        $this->order->fireWorkflow(
            WorkflowEnum::STATUS_TRANSITION->value,
            true,
            [
                'app' => $this->order->app,
                'from_status' => $currentOrderStatus->slug,
                'to_status' => $this->newOrderStatus->slug,
                'who' => $this->user,
            ]
        );


        activity('change-order-status')
            ->causedBy($this->user)
            ->performedOn($this->order)
            ->withProperties([
                'order_metadata' => $this->order->metadata,
                'from_status' => $currentOrderStatus->slug,
                'to_status' => $this->newOrderStatus->slug,
                'who' => $this->user,
            ])
            ->log('User changed order status');

        return [
            'status' => 'success',
            'message' => 'Order status transitioned successfully',
        ];
    }
}
