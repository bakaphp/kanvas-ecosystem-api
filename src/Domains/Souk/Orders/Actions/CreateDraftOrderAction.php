<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Souk\Orders\DataTransferObject\DraftOrder;
use Kanvas\Souk\Orders\Models\Order as ModelsOrder;

class CreateDraftOrderAction
{
    public function __construct(
        protected DraftOrder $orderData
    ) {
    }

    public function execute(): ModelsOrder
    {
        return DB::connection('commerce')->transaction(function () {
            $order = new ModelsOrder();
            $order->apps_id = $this->orderData->app->getId();
            $order->region_id = $this->orderData->region->getId();
            $order->companies_id = $this->orderData->branch->company->getId();
            $order->people_id = $this->orderData->people->getId();
            $order->users_id = $this->orderData->user->getId();
            $order->user_email = $this->orderData->email;
            $order->user_phone = $this->orderData->phone;
            $order->token = null;
            $order->shipping_address_id = $this->orderData?->shippingAddress?->getId() ?? null;
            $order->billing_address_id = $this->orderData?->billingAddress?->getId() ?? null;
            $order->total_gross_amount = $this->orderData->total;
            $order->total_net_amount = $this->orderData->total - $this->orderData->taxes;
            $order->shipping_price_gross_amount = $this->orderData->totalShipping;
            $order->shipping_price_net_amount = $this->orderData->totalShipping;
            $order->discount_amount = $this->orderData->totalDiscount;
            $order->status = $this->orderData->status;
            $order->fulfillment_status = 'pending';
            $order->currency = $this->orderData->currency->code;
            $order->metadata = $this->orderData->metadata;
            $order->payment_gateway_names = $this->orderData->paymentGatewayName;
            //$order->language_code = $this->orderData->languageCode;
            $order->saveOrFail();

            $order->addItems($this->orderData->items);

            return $order;
        });
    }
}
