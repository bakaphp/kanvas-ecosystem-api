<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Repositories;

use Kanvas\Souk\Orders\Models\Order;

class OrderRepository
{
    public function __construct(
        protected Order $order
    ) {
    }

    public function getStatus(
        string $statusSlug,
    ): ?object {
        $status = $this->order->orderType?->statuses()
                    ->where('slug', $statusSlug)->first();
        return $status;
    }
}
