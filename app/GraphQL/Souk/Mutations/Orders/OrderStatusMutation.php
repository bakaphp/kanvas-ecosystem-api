<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Mutations\Orders;

use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Actions\CreateOrderStatusAction;
use Kanvas\Souk\Orders\Actions\UpdateOrderStatusAction;
use Kanvas\Souk\Orders\DataTransferObject\OrderStatus as OrderStatusData;
use Kanvas\Souk\Orders\Models\OrderStatus;

class OrderStatusMutation
{
    public function create(mixed $root, array $request): OrderStatus
    {
        $app = app(Apps::class);

        return new CreateOrderStatusAction(
            OrderStatusData::from($request['input']),
            $app,
        )->execute();
    }

    public function update(mixed $root, array $request): OrderStatus
    {
        $app = app(Apps::class);

        $status = OrderStatus::where([
            'apps_id' => $app->getId(),
            'id' => $request['id'],
        ])->first();

        if (! $status) {
            throw new ValidationException('Order status not found');
        }

        return new UpdateOrderStatusAction(
            $status,
            OrderStatusData::from($request['input']),
        )->execute();
    }

    public function delete(mixed $root, array $request): bool
    {
        $app = app(Apps::class);

        $status = OrderStatus::where([
            'apps_id' => $app->getId(),
            'id' => $request['id'],
            'is_deleted' => 0,
        ])->first();

        if (! $status) {
            throw new ValidationException('Order status not found');
        }

        $orderType = $status->orderType;

        $status->delete();

        $orderType->update([
            'total_statuses' => OrderStatus::where('order_types_id', $orderType->getId())
                ->where('is_deleted', 0)
                ->count(),
        ]);

        return true;
    }
}
