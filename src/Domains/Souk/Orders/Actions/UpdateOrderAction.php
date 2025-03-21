<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException as EloquentModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Souk\Orders\DataTransferObject\OrderItem;
use Kanvas\Souk\Orders\Models\Order as ModelsOrder;
use Spatie\LaravelData\DataCollection;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Notifications\NewOrderNotification;
use Kanvas\Workflow\Enums\WorkflowEnum;

class UpdateOrderAction
{
    public bool $runWorkflow = true;

    public function __construct(
        protected int $orderId,
        protected array $orderData,
        protected Companies $company,
        protected Regions $region,
        protected UserInterface $user,
        protected Apps $app,
    ) {}

    public function execute(): ModelsOrder
    {

        $total = 0;
        $totalTax = 0;
        $totalDiscount = 0;
        $lineItems = [];

        foreach ($this->orderData['items'] as $key => $lineItem) {
            $lineItems[$key] = OrderItem::viaRequest($this->app, $this->company, $this->region, $lineItem);
            $total += $lineItems[$key]->getTotal();
            $totalTax += $lineItems[$key]->getTotalTax();
            $totalDiscount = $lineItems[$key]->getTotalDiscount();
        }

        $items = OrderItem::collect($lineItems, DataCollection::class);


        return DB::connection('commerce')->transaction(function () use ($items) {
            // Lock the table for uniqueness check
            $order = ModelsOrder::where([
                'apps_id' => $this->app->getId(),
                'id' => $this->orderId
            ])->lockForUpdate()->first();

            if ($order->fulfillment_status === 'fulfilled') {
                throw new ValidationException('Order is already fulfilled');
            }

            $order->metadata = $this->orderData['metadata'];

            $order->saveOrFail();

            $order->deleteItems();
            $order->addItems($items->toArray());

            // Run after commit
            DB::afterCommit(function () use ($order) {
                if ($this->runWorkflow) {
                    $order->fireWorkflow(
                        WorkflowEnum::UPDATED->value,
                        true,
                        [
                            'app' => $this->app,
                        ]
                    );
                }

                try {
                    $order->user->notify(new NewOrderNotification($order, [
                        'app' => $this->app,
                        'company' => $this->company,
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

            return $order;
        });
    }


    public function disableWorkflow(): self
    {
        $this->runWorkflow = false;

        return $this;
    }
}
