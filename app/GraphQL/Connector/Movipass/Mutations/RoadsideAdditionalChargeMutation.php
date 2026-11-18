<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\Movipass\Mutations;

use App\GraphQL\Concerns\ResolvesActingContext;
use App\GraphQL\Connector\Movipass\Concerns\ResolvesRegionalRoadsideOrder;
use Kanvas\Connectors\Movipass\Actions\AddRoadsideAdditionalChargeAction;
use Kanvas\Connectors\Movipass\Enums\RoadsideAdditionalChargeConceptEnum;

class RoadsideAdditionalChargeMutation
{
    use ResolvesActingContext;
    use ResolvesRegionalRoadsideOrder;

    public function add(mixed $rootValue, array $request): array
    {
        $ctx = $this->actingContext();
        $input = $request['input'];

        return new AddRoadsideAdditionalChargeAction(
            order: $this->regionalRoadsideOrder($ctx->app, $input['order_id']),
            registeredBy: $ctx->user,
            concept: RoadsideAdditionalChargeConceptEnum::from((string) $input['concept']),
            amount: (float) $input['amount'],
            notes: $input['notes'] ?? null,
        )->execute();
    }
}
