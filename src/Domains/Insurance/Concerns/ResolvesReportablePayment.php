<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Concerns;

use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Models\Payments;

trait ResolvesReportablePayment
{
    protected function reportablePayment(Order $order): ?Payments
    {
        return $order->payments()
            ->whereIn('status', [
                PaymentStatusEnum::AUTHORIZED->value,
                PaymentStatusEnum::PAID->value,
            ])
            ->orderByDesc('id')
            ->first();
    }
}
