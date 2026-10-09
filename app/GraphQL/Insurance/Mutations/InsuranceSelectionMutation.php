<?php

declare(strict_types=1);

namespace App\GraphQL\Insurance\Mutations;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Insurance\Actions\SelectInsurancePlanAction;
use Kanvas\Insurance\DataTransferObject\InsurancePlanSelection;

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
}
