<?php

declare(strict_types=1);

namespace App\GraphQL\Insurance\Mutations;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Insurance\Actions\ConvertInsuranceLeadToOrderAction;
use Kanvas\Insurance\Actions\SelectInsurancePlanAction;
use Kanvas\Insurance\DataTransferObject\InsuranceLeadContract;
use Kanvas\Insurance\DataTransferObject\InsurancePlanSelection;
use Kanvas\Souk\Orders\Models\Order;

class InsuranceSelectionMutation
{
    use ResolvesActingContext;

    public function select(mixed $rootValue, array $request): Lead
    {
        $ctx = $this->actingContext();

        return new SelectInsurancePlanAction(
            InsurancePlanSelection::fromMultiple($ctx->app, $ctx->user, $ctx->company, $request['input'])
        )->execute();
    }

    public function contract(mixed $rootValue, array $request): Order
    {
        $ctx = $this->actingContext();

        return new ConvertInsuranceLeadToOrderAction(
            InsuranceLeadContract::fromMultiple($ctx->app, $ctx->user, $ctx->company, $request['input'])
        )->execute();
    }
}
