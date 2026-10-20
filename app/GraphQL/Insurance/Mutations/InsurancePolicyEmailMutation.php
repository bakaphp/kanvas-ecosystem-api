<?php

declare(strict_types=1);

namespace App\GraphQL\Insurance\Mutations;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Insurance\Actions\SendInsurancePolicyEmailAction;
use Kanvas\Souk\Orders\Models\Order;

class InsurancePolicyEmailMutation
{
    use ResolvesActingContext;

    public function send(mixed $rootValue, array $request): bool
    {
        $ctx = $this->actingContext();
        $order = Order::getById((int) $request['order_id'], $ctx->app);

        if ((int) $order->users_id !== $ctx->user->getId()) {
            throw new ModelNotFoundException('Order not found');
        }

        new SendInsurancePolicyEmailAction($order)->execute();

        return true;
    }
}
