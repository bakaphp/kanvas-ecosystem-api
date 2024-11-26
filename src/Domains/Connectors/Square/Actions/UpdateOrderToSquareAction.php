<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Square\Actions;

use Kanvas\Connectors\Square\Client;
use Kanvas\Connectors\Square\Enums\CustomFieldEnum;
use Kanvas\Souk\Orders\Models\Order;
use Square\Models\Order as ModelsOrder;
use Square\Models\UpdateOrderRequest;

class UpdateOrderToSquareAction
{
    public function __construct(
        protected Order $order,
    ) {
    }

    public function execute(): ?ModelsOrder
    {
        $client = (new Client($this->order->app))->getClient();

        if (! $paymentId = $this->order->get(CustomFieldEnum::SQUARE_ORDER_ID->value)) {
            return null;
        }

        $customerId = (new SavePeopleToSquareCustomerAction($this->order->people))->execute()->getId();
        $note = 'Updated by API: Assigned customer and added note';

        // Step 1: Retrieve the payment details
        $paymentsApi = $client->getPaymentsApi();
        $paymentResponse = $paymentsApi->getPayment($paymentId);

        if ($paymentResponse->isSuccess()) {
            $payment = $paymentResponse->getResult()->getPayment();
            $orderId = $payment->getOrderId();

            if ($orderId) {
                // Step 2: Update the order
                $ordersApi = $client->getOrdersApi();
                // Create an Order object with the updates
                $order = new ModelsOrder($orderId);
                $order->setCustomerId($customerId);
                $order->setMetadata(['note' => $note]);

                // Create the UpdateOrderRequest object
                $updateOrderRequest = new UpdateOrderRequest();
                $updateOrderRequest->setOrder($order);
                $updateOrderRequest->setIdempotencyKey(uniqid()); // Unique key for this update request

                // Call the API
                $updateOrderResponse = $ordersApi->updateOrder($orderId, $updateOrderRequest);

                if ($updateOrderResponse->isSuccess()) {
                    return $updateOrderResponse->getResult()->getOrder();
                }
            }
        }

        return null;
    }
}
