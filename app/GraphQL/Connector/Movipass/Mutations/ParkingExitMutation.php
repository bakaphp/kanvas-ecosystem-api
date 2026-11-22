<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\Movipass\Mutations;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\Connectors\Movipass\Parking\Actions\QuoteParkingExitAction;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Souk\Orders\Models\Order;

class ParkingExitMutation
{
    use ResolvesActingContext;

    public function quote(mixed $rootValue, array $request): Order
    {
        $ctx = $this->actingContext();

        $order = Order::query()
            ->fromApp($ctx->app)
            ->notDeleted()
            ->whereKey((int) $request['order_id'])
            ->where('users_id', $ctx->user->getId())
            ->first();

        if ($order === null) {
            throw new ModelNotFoundException('Order not found');
        }

        new QuoteParkingExitAction($order)->execute();

        return $order->refresh();
    }
}
