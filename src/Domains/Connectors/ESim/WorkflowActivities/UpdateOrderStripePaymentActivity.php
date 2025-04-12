<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ESim\WorkflowActivities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\ESim\Enums\CustomFieldEnum;
use Kanvas\Connectors\WooCommerce\Services\WooCommerceOrderService;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\KanvasActivity;

class UpdateOrderStripePaymentActivity extends KanvasActivity
{
    //public $tries = 2;

    public function execute(Order $order, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);
        $orderCommerceId = $order->get(CustomFieldEnum::WOOCOMMERCE_ORDER_ID->value);

        $orderPaymentIntent = $order->getPrivateMetadata('stripe_payment_intent');

        if (empty($orderPaymentIntent)) {
            return [
                'status' => 'error',
                'message' => 'No payment intent found in the order metadata',
                'order' => $order->getId(),
                'response' => null,
            ];
        }

        $commerceOrder = new WooCommerceOrderService($order->app);
        $response = $commerceOrder->updateOrderStripePayment(
            $orderCommerceId,
            $orderPaymentIntent['latest_charge'],
            'completed'
        );

        return [
            'status' => 'success',
            'message' => 'Order updated successfully',
            'order' => $order->getId(),
            'response' => $response,
        ];
    }
}
