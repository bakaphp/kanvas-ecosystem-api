<?php

namespace Kanvas\Souk\Orders\Actions;

use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderStatus;
use Kanvas\Souk\Orders\Models\OrderTransitionHistory;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Enums\WorkflowEnum;

class TransitionOrderStateAction
{
    public function __construct(
        protected Order $order,
        protected Users $user,
        protected ?OrderStatus $newOrderStatus = null,
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

        try {
            DB::transaction(function () use ($orderStatusTransitions, $currentOrderStatus) {
                // Update the order status transition
                $current = OrderTransitionHistory::where('order_id', $this->order->id)
                ->where('is_current', true)
                ->first();

                if ($current) {
                    $now = Carbon::now();
                    $duration = $current->changed_at->diffInSeconds($now);

                    $current->updateQuietly([
                        'is_current' => false,
                        'status_ended_at' => $now,
                        'duration_in_seconds' => $duration,
                        'ended_by' => $this->user->getId(),
                    ]);
                }

                // Update the order status
                $this->order->updateQuietly(['order_status_id' => $this->newOrderStatus->id]);

                // Insert into order_transitions_history
                OrderTransitionHistory::create([
                    'apps_id' => $this->order->apps_id,
                    'companies_id' => $this->order->companies_id,
                    'transition_id' => $orderStatusTransitions->id,
                    'order_id' => $this->order->id,
                    'from_status_id' => $currentOrderStatus->id,
                    'to_status_id' => $this->newOrderStatus->id,
                    'description' => 'Order status changed from ' . $currentOrderStatus->slug . ' to ' . $this->newOrderStatus->slug,
                    'metadata' => is_array($this->order->metadata) ? json_encode($this->order->metadata) : $this->order->metadata,
                    'is_current' => true,
                    'changed_at' => now(),
                    'changed_by' => $this->user->getId(),
                ]);
            });

            // Fire workflow after successful transaction
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

            return [
                'status' => 'success',
                'message' => 'Order status transitioned successfully',
            ];
        } catch (Exception $e) {
            if ($processQuietly) {
                return [
                    'status' => 'error',
                    'message' => 'Failed to transition order status: ' . $e->getMessage(),
                ];
            }
            throw $e;
        }
    }


    public function setInitialState(): void
    {
        $defaultStatus = $this->order->orderType->defaultStatus;

        if (! $defaultStatus) {
            return;
        }

        $this->order->order_status_id = $defaultStatus->id;
        $this->order->saveOrFail();

        OrderTransitionHistory::create([
            'apps_id' => $this->order->apps_id,
            'companies_id' => $this->order->companies_id,
            'order_id' => $this->order->id,
            'transition_id' => null,
            'from_status_id' => null,
            'to_status_id' => $defaultStatus->id,
            'metadata' => is_array($this->order->metadata) ? json_encode($this->order->metadata) : $this->order->metadata,
            'is_current' => true,
            'changed_at' => now(),
            'changed_by' => $this->user->getId(),
        ]);
    }
}
