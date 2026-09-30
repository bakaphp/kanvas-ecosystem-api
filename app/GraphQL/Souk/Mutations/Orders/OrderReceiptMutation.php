<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Mutations\Orders;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Souk\Orders\Actions\GenerateOrderReceiptAction;
use Kanvas\Souk\Orders\Models\Order;
use Throwable;

class OrderReceiptMutation
{
    use ResolvesActingContext;

    public function generate(mixed $root, array $request): Filesystem
    {
        $ctx = $this->actingContext();
        $order = Order::getById((int) $request['id'], $ctx->app);

        try {
            return new GenerateOrderReceiptAction($order, $ctx->user)->execute();
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);

            throw new ValidationException('The receipt PDF could not be generated, please try again');
        }
    }
}
