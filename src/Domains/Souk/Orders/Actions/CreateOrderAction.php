<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Actions;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Exceptions\ModelNotFoundException as ExceptionsModelNotFoundException;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\DataTransferObject\Order;
use Kanvas\Souk\Orders\Models\Order as ModelsOrder;
use Kanvas\Souk\Orders\Notifications\NewOrderNotification;
use Kanvas\Souk\Orders\Notifications\NewOrderStoreOwnerNotification;
use Kanvas\Souk\Orders\Validations\UniqueOrderNumber;
use Kanvas\Users\Services\UserRoleNotificationService;
use Kanvas\Workflow\Enums\WorkflowEnum;

class CreateOrderAction
{
    public bool $runWorkflow = true;

    public function __construct(
        protected Order $orderData
    ) {
    }

    public function execute(): ModelsOrder
    {
        $validator = Validator::make(
            ['order_number' => $this->orderData->orderNumber],
            ['order_number' => new UniqueOrderNumber($this->orderData->app, $this->orderData->company, $this->orderData->region)]
        );

        if ($validator->fails()) {
            throw new ValidationException($validator->messages()->__toString());
        }

        return DB::connection('commerce')->transaction(function () {
            $order = new ModelsOrder();
            $order->apps_id = $this->orderData->app->getId();
            $order->region_id = $this->orderData->region->getId();
            $order->companies_id = $this->orderData->company->getId();
            $order->people_id = $this->orderData->people->getId();
            $order->users_id = $this->orderData->user->getId();
            $order->user_email = $this->orderData->email;
            $order->user_phone = $this->orderData->phone;
            $order->token = $this->orderData->token;
            $order->order_number = $this->orderData->orderNumber;
            $order->shipping_address_id = $this->orderData?->shippingAddress?->getId() ?? null;
            $order->billing_address_id = $this->orderData?->billingAddress?->getId() ?? null;
            $order->total_gross_amount = $this->orderData->total;
            $order->total_net_amount = $this->orderData->total - $this->orderData->taxes;
            $order->shipping_price_gross_amount = $this->orderData->totalShipping;
            $order->shipping_price_net_amount = $this->orderData->totalShipping;
            $order->discount_amount = $this->orderData->totalDiscount;
            $order->status = $this->orderData->status;
            $order->shipping_method_name = $this->orderData->shippingMethod;
            $order->fulfillment_status = $this->orderData->fulfillmentStatus;
            $order->weight = $this->orderData->weight;
            $order->checkout_token = $this->orderData->checkoutToken;
            $order->currency = $this->orderData->currency->code;
            $order->metadata = $this->orderData->metadata;
            $order->payment_gateway_names = $this->orderData->paymentGatewayName;
            $order->language_code = $this->orderData->languageCode;
            $order->saveOrFail();

            $order->addItems($this->orderData->items);

            if ($this->runWorkflow) {
                $order->fireWorkflow(
                    WorkflowEnum::CREATED->value,
                    true,
                    [
                        'app' => $this->orderData->app,
                    ]
                );
            }

            try {
                $order->user->notify(new NewOrderNotification($order, [
                    'app' => $this->orderData->app,
                    'company' => $this->orderData->company,
                ]));
            } catch (ModelNotFoundException|ExceptionsModelNotFoundException $e) {
            }

            try {
                UserRoleNotificationService::notify(
                    RolesEnums::ADMIN->value,
                    new NewOrderStoreOwnerNotification(
                        $order,
                        [
                            'app' => $this->orderData->app,
                            'company' => $this->orderData->company,
                        ]
                    ),
                    $this->orderData->app
                );
            } catch (ModelNotFoundException|ExceptionsModelNotFoundException $e) {
            }

            return $order;
        });
    }

    public function disableWorkflow(): self
    {
        $this->runWorkflow = false;

        return $this;
    }
}
