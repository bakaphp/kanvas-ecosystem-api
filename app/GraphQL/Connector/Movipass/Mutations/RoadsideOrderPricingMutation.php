<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\Movipass\Mutations;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\Connectors\Movipass\Actions\ApplyRoadsideQuoteToOrderAction;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Souk\Orders\Models\Order;

class RoadsideOrderPricingMutation
{
    use ResolvesActingContext;

    public function price(mixed $rootValue, array $request): Order
    {
        $ctx = $this->actingContext();

        /** @var Order|null $order */
        $order = Order::query()
            ->fromApp($ctx->app)
            ->notDeleted()
            ->whereKey((int) $request['order_id'])
            ->where('users_id', $ctx->user->getId())
            ->first();

        // Someone else's order answers exactly like a missing one.
        if ($order === null) {
            throw new ModelNotFoundException('Order not found');
        }

        new ApplyRoadsideQuoteToOrderAction($order)->execute();

        return $order->refresh();
    }
}
