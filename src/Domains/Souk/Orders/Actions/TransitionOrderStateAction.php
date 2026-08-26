<?php

namespace Kanvas\Souk\Orders\Actions;

use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderItem;
use Kanvas\Souk\Orders\Models\OrderStatus;
use Kanvas\Souk\Orders\Models\OrderTransitionHistory;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
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

    public function execute(bool $processQuietly = false, ?string $customDate = null): array
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
            $transitioned = false;

            // orders and their history live on `commerce`, so a default-connection transaction would
            // leave lockForUpdate and both writes in autocommit — concurrent transitions then race
            // and each leaves its own is_current row open
            DB::connection('commerce')->transaction(function () use ($orderStatusTransitions, $currentOrderStatus, $customDate, &$transitioned) {
                // Lock the order row to prevent concurrent transitions
                $locked = Order::lockForUpdate()->find($this->order->id);

                if ($locked->order_status_id !== $currentOrderStatus->id) {
                    return; // another process already transitioned, skip
                }

                $transitioned = true;

                // close every open row, not just the first — orders already carrying more than one
                // would otherwise keep an open interval forever
                $openTransitions = OrderTransitionHistory::where('order_id', $this->order->id)
                    ->where('is_current', true)
                    ->get();

                $closedAt = $customDate ? Carbon::parse($customDate) : Carbon::now();

                foreach ($openTransitions as $openTransition) {
                    $openTransition->updateQuietly([
                        'is_current' => false,
                        'ended_at' => $closedAt,
                        'duration_in_seconds' => $openTransition->changed_at->diffInSeconds($closedAt),
                        'ended_by' => $this->user->getId(),
                    ]);
                }

                $attributes = ['order_status_id' => $this->newOrderStatus->id];

                if ($this->newOrderStatus->slug === PaymentStatusEnum::PAID->value) {
                    $attributes['payment_status'] = PaymentStatusEnum::PAID->value;
                }

                $this->order->updateQuietly($attributes);

                // Insert into order_transitions_history — same instant the previous row closed, so the
                // intervals stay contiguous and a cutoff can never fall in a gap between them
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
                    'changed_at' => $closedAt,
                    'changed_by' => $this->user->getId(),
                    ...$this->amountSnapshot(),
                ]);
            });

            if ($transitioned) {
                $this->fireWorkflow($currentOrderStatus);
            }


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
        $this->newOrderStatus = $defaultStatus;

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
            ...$this->amountSnapshot(),
        ]);

        $this->fireWorkflow(null);
    }

    private function amountSnapshot(): array
    {
        return [
            'total_gross_amount' => (float) $this->order->total_gross_amount,
            'discount_amount' => (float) $this->order->discount_amount,
            'total_net_amount' => (float) $this->order->total_net_amount,
            'items_snapshot' => $this->order->allItems()->get()->map(fn (OrderItem $item) => [
                'order_item_id' => $item->id,
                'variant_id' => $item->variant_id,
                'product_name' => $item->product_name,
                'quantity' => (float) $item->quantity,
                'unit_price' => (float) $item->unit_price_net_amount,
            ])->all(),
        ];
    }

    private function fireWorkflow($currentOrderStatus): void
    {
        $this->order->fireWorkflow(
            WorkflowEnum::STATUS_TRANSITION->value,
            true,
            [
                'app' => $this->order->app,
                'from_status' => $currentOrderStatus?->slug,
                'to_status' => $this->newOrderStatus->slug,
                'who' => $this->user,
            ]
        );


        // $activity = new TookanOrderStatusActivity(
        //     0,
        //     now()->toDateTimeString(),
        //     StoredWorkflow::make(),
        //     []
        // );

        // $result = $activity->execute($this->order, $this->order->app, [
        //     'currentEventTypeName' =>  WorkflowEnum::STATUS_TRANSITION->value,
        //     'app' => $this->order->app,
        //     'from_status' => $currentOrderStatus?->slug ?? null,
        //     'to_status' => $this->newOrderStatus->slug,
        //     'who' => $this->user,
        // ]);
    }
}
