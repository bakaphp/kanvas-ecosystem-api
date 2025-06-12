<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Mutations\Payments;

use Exception;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Actions\MakePaymentIntentAction;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Payments\Actions\CreatePaymentAction;

class PaymentMutation
{
    public function makePaymentIntent($_, array $request): array
    {
        $app = app(Apps::class);
        $paymentId = (int) $request['paymentID'];

        $payment = Payments::where([
            'apps_id' => $app->getId(),
            'id' => $paymentId,
        ])->first();

        if (! $payment) {
            throw new Exception('Payment not found');
        }

        $paymentIntent = new MakePaymentIntentAction($payment);

        return [
            "paymentIntent" => $paymentIntent->execute(),
            "message" => "message",
        ];
    }

    public function makePaymentIntentFromOrder($_, array $request): array
    {
        $app = app(Apps::class);
        $orderId = (int) $request['orderID'];

        $payment = Payments::where([
            'apps_id' => $app->getId(),
            'payable_id' => $orderId,
            'payable_type' => Order::class,
        ])->first();

        if (! $payment) {
            throw new Exception('Payment not found');
        }

        $paymentIntent = new MakePaymentIntentAction($payment);

        return [
            "paymentIntent" => $paymentIntent->execute(),
            "message" => "message",
        ];
    }

    public function addPaymentToOrder($_, array $request): array {
        $app = app(Apps::class);
        $orderId = (int) $request['orderID'];

        $order = Order::where([
            'apps_id' => $app->getId(),
            'id' => $orderId,
        ])->first();

        if ($order->isFulfilled()) {
            throw new ValidationException('Order is already fulfilled');
        }

        if ($order->isCompleted()) {
            throw new ValidationException('Order is already completed');
        }

        $formData = $request['input'];

        if ($order->metadata && isset($order->metadata['data']['payment_methods_id'])) {
            $payment = new CreatePaymentAction($order)->execute($formData);
        }

        return [
            "payment" => $payment,
            "message" => "message",
        ];
    }
}
