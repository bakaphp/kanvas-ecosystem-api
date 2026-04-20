<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Souk\Orders\Models\Order;

class GetOrdersByUserCustomFieldAction
{
    public function __construct(
        protected readonly AppInterface $app,
        protected readonly string $customFieldName,
        protected readonly int|string $userId,
    ) {
    }

    public function execute(): Builder
    {
        return Order::getByCustomFieldBuilder(
            $this->customFieldName,
            (string) $this->userId,
            null,
            false
        )
            ->where('orders.apps_id', $this->app->getId())
            ->where('orders.is_deleted', 0);
    }
}
