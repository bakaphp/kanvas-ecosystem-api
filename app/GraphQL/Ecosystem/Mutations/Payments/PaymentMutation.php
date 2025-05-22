<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Payments;

use Exception;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Actions\MakePaymentIntentAction;
use Kanvas\Souk\Payments\Models\Payments;

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
}
