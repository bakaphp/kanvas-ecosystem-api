<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException as EloquentModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Souk\Orders\DataTransferObject\OrderItem;
use Kanvas\Souk\Orders\Models\Order as ModelsOrder;
use Kanvas\Souk\Orders\Notifications\NewOrderNotification;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Spatie\LaravelData\DataCollection;

class UpdateOrderAction
{
    public bool $runWorkflow = true;

    public function __construct(
        protected ModelsOrder $order,
        protected array $orderData,
        protected UserInterface $user,
    ) {
    }

    public function execute(): ModelsOrder
    {
        // Capture original values for activity logging
        $originalValues = [
            'metadata' => $this->order->metadata,
            'fulfillment_status' => $this->order->fulfillment_status,
            'items_count' => $this->order->items()->count(),
        ];

        $total = 0;
        $totalTax = 0;
        $totalDiscount = 0;
        $lineItems = [];

        $hasItems = isset($this->orderData['items']);

        if ($hasItems) {
            foreach ($this->orderData['items'] as $key => $lineItem) {
                $lineItems[$key] = OrderItem::viaRequest($this->order->app, $this->order->company, $this->order->region, $lineItem);
                $total += $lineItems[$key]->getTotal();
                $totalTax += $lineItems[$key]->getTotalTax();
                $totalDiscount = $lineItems[$key]->getTotalDiscount();
            }

            $lineItems = OrderItem::collect($lineItems, DataCollection::class);
        }

        return DB::connection('commerce')->transaction(function () use ($lineItems, $hasItems, $originalValues) {
            $currentMetadata = is_array($this->order->metadata) ? $this->order->metadata : [];
            $newMetadata = is_array($this->orderData['metadata'] ?? null) ? $this->orderData['metadata'] : [];

            $this->order->metadata = $this->mergeMetadata(
                $currentMetadata,
                $newMetadata,
                $this->orderData['metadata_action'] ?? $this->order->get('ORDER_METADATA_ACTION', 'MERGE') ?? 'MERGE'
            );

            $this->order->fulfillment_status = $this->orderData['fulfillment_status'] ?? $this->order->fulfillment_status;
            $this->order->saveOrFail();

            if ($hasItems) {
                $this->order->deleteItems();
                $this->order->addItems($lineItems);
            }

            // Log the activity after changes are made
            $this->logOrderActivity(
                $originalValues,
                $hasItems,
                $lineItems
            );

            // Run after commit
            DB::afterCommit(function () {
                if ($this->runWorkflow) {
                    $this->order->fireWorkflow(
                        WorkflowEnum::UPDATED->value,
                        true,
                        [
                            'app' => $this->order->app,
                        ]
                    );
                }

                try {
                    $this->order->user->notify(new NewOrderNotification($this->order, [
                        'app' => $this->order->app,
                        'company' => $this->order->company,
                    ]));
                } catch (ModelNotFoundException | EloquentModelNotFoundException $e) {
                    // Handle notification failure
                }

                try {
                    /**
                     * @todo move to workflow
                     */
                    /*  UserRoleNotificationService::notify(
                     RolesEnums::ADMIN->value,
                     new NewOrderStoreOwnerNotification(
                         $order,
                         [
                             'app' => $this->orderData->app,
                             'company' => $this->orderData->company,
                         ]
                     ),
                     $this->orderData->app
                 ); */
                } catch (EloquentModelNotFoundException $e) {
                    // Handle admin notification failure
                }
            });

            return $this->order;
        });
    }

    public function disableWorkflow(): self
    {
        $this->runWorkflow = false;

        return $this;
    }

    private function logOrderActivity(array $originalValues, bool $hasItems, $lineItems = null): void
    {
        $changes = [];

        // Track metadata changes
        if ($originalValues['metadata'] !== $this->order->metadata) {
            $changes['metadata'] = [
                'old' => $originalValues['metadata'],
                'new' => $this->order->metadata,
            ];
        }

        // Track fulfillment status changes
        if ($originalValues['fulfillment_status'] !== $this->order->fulfillment_status) {
            $changes['fulfillment_status'] = [
                'old' => $originalValues['fulfillment_status'],
                'new' => $this->order->fulfillment_status,
            ];
        }

        // Track items changes
        if ($hasItems) {
            $newItemsCount = $lineItems ? $lineItems->count() : 0;
            $changes['items'] = [
                'old_count' => $originalValues['items_count'],
                'new_count' => $newItemsCount,
                'action' => 'replaced_all_items',
            ];
        }

        // Only log if there are actual changes
        if (! empty($changes)) {
            activity()
                ->causedBy($this->user)
                ->performedOn($this->order)
                ->withProperties([
                    'changes' => $changes,
                    'order_id' => $this->order->id,
                    'order_number' => $this->order->order_number,
                ])
                ->log('Order updated');
        }
    }

    private function mergeMetadata(
        array $currentMetadata,
        array $newMetadata,
        string $metadataAction = 'MERGE'
    ): array {
        $currentMetadata = is_array($currentMetadata) ? $currentMetadata : [];
        $newMetadata = is_array($newMetadata) ? $newMetadata : [];

        if ($metadataAction === 'REPLACE') {
            return [
                ...$currentMetadata,
                ...$newMetadata,
            ];
        } elseif ($metadataAction === 'CLEAR') {
            return $newMetadata;
        }

        // Merge mode: preserve old, update existing (overwrite unless null), add new
        $result = $currentMetadata;

        foreach ($newMetadata as $key => $value) {
            if ($key === 'data') {
                // Merge data array - overwrite non-null values
                $result['data'] = $this->mergeArrayRecursive(
                    $currentMetadata['data'] ?? [],
                    $newMetadata['data'] ?? []
                );
            } elseif (is_array($value) && isset($result[$key]) && is_array($result[$key])) {
                // Recursively merge nested arrays - overwrite all values
                $result[$key] = $this->mergeArrayRecursive($result[$key], $value);
            } elseif ($value !== null) {
                // Overwrite scalar values, but skip if null
                $result[$key] = $value;
            }
            // If $value is null, keep the old value (don't overwrite)
        }

        return $result;
    }

    private function mergeArrayRecursive(array $current, array $new): array
    {
        foreach ($new as $key => $value) {
            if ($value === null) {
                // Skip null values, keep old
                continue;
            } elseif (is_array($value) && isset($current[$key]) && is_array($current[$key])) {
                // Recursively merge nested arrays
                $current[$key] = $this->mergeArrayRecursive($current[$key], $value);
            } else {
                // Overwrite (including empty arrays, type changes, etc)
                $current[$key] = $value;
            }
        }

        return $current;
    }
}
