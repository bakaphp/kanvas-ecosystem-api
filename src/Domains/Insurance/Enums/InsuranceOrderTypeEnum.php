<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Enums;

use Kanvas\Souk\Orders\Models\Order;

enum InsuranceOrderTypeEnum: string
{
    case INSURANCE = 'insurance';

    public function isTypeOf(Order $order): bool
    {
        return $order->order_types_id !== null && $order->orderType?->name === $this->value;
    }
}
