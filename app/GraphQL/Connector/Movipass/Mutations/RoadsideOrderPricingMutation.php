<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\Movipass\Mutations;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\Connectors\Movipass\Actions\ApplyRoadsideQuoteToOrderAction;
use Kanvas\Souk\Orders\Models\Order;

class RoadsideOrderPricingMutation
{
    use ResolvesActingContext;

    public function price(mixed $rootValue, array $request): Order
    {
        $ctx = $this->actingContext();

        /** @var Order $order */
        $order = Order::query()
            ->fromApp($ctx->app)
            ->notDeleted()
            ->whereKey((int) $request['order_id'])
            ->where('users_id', $ctx->user->getId())
            ->firstOrFail();

        new ApplyRoadsideQuoteToOrderAction($order)->execute();

        return $order->refresh();
    }
}
