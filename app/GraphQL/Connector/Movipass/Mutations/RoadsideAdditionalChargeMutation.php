<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\Movipass\Mutations;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\Connectors\Movipass\Actions\AddRoadsideAdditionalChargeAction;
use Kanvas\Connectors\Movipass\Enums\RoadsideAdditionalChargeConceptEnum;
use Kanvas\Souk\Orders\Models\Order;

class RoadsideAdditionalChargeMutation
{
    use ResolvesActingContext;

    public function add(mixed $rootValue, array $request): array
    {
        $ctx = $this->actingContext();
        $input = $request['input'];

        // The cabin works orders from every customer company, so the lookup is app-scoped and the
        // schema's @can is what limits who may bill.
        /** @var Order $order */
        $order = Order::query()
            ->fromApp($ctx->app)
            ->notDeleted()
            ->whereKey((int) $input['order_id'])
            ->firstOrFail();

        return new AddRoadsideAdditionalChargeAction(
            order: $order,
            registeredBy: $ctx->user,
            concept: RoadsideAdditionalChargeConceptEnum::from((string) $input['concept']),
            amount: (float) $input['amount'],
            notes: $input['notes'] ?? null,
        )->execute();
    }
}
