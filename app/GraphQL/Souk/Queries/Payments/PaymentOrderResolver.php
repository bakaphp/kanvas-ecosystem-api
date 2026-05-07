<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Queries\Payments;

use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Models\Payments;

class PaymentOrderResolver
{
    public function __invoke(Payments $payment): ?Order
    {
        if ($payment->payable_type !== Order::class) {
            return null;
        }

        return Order::find($payment->payable_id);
    }
}
