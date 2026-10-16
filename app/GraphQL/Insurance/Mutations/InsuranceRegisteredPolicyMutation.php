<?php

declare(strict_types=1);

namespace App\GraphQL\Insurance\Mutations;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\Insurance\Actions\RegisterInsurancePolicyAction;
use Kanvas\Insurance\DataTransferObject\RegisteredInsurancePolicy;
use Kanvas\Souk\Orders\Models\Order;

class InsuranceRegisteredPolicyMutation
{
    use ResolvesActingContext;

    public function register(mixed $rootValue, array $request): Order
    {
        $ctx = $this->actingContext();

        return new RegisterInsurancePolicyAction(
            RegisteredInsurancePolicy::fromMultiple($ctx->app, $ctx->user, $ctx->company, $request['input'])
        )->execute();
    }
}
