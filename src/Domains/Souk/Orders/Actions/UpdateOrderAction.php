<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Actions;

use Illuminate\Database\Eloquent\ModelNotFoundException as EloquentModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Souk\Orders\DataTransferObject\OrderItem;
use Kanvas\Souk\Orders\Models\Order as ModelsOrder;
use Spatie\LaravelData\DataCollection;
use Kanvas\Souk\Orders\Notifications\NewOrderNotification;
use Kanvas\Workflow\Enums\WorkflowEnum;

class UpdateOrderAction
{
    public bool $runWorkflow = true;

    public function __construct(
        protected ModelsOrder $order,
        protected array $orderData,
    ) {
    }

    public function execute(): ModelsOrder
    {
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

        return DB::connection('commerce')->transaction(function () use ($lineItems, $hasItems) {
            $this->order->metadata = [
                ...($this->order->metadata ?? []),
                'data' => [
                    ...($this->order->metadata['data'] ?? []),
                    ...($this->orderData['metadata']['data'] ?? []),
                ],
            ];
            $this->order->saveOrFail();

            if ($hasItems) {
                $this->order->deleteItems();
                $this->order->addItems($lineItems);
            }

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
}
