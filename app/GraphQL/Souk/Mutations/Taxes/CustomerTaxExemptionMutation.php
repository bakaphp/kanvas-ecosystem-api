<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Mutations\Taxes;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\Souk\Taxes\Actions\CreateCustomerTaxExemptionAction;
use Kanvas\Souk\Taxes\Actions\UpdateCustomerTaxExemptionAction;
use Kanvas\Souk\Taxes\Models\CustomerTaxExemption;

class CustomerTaxExemptionMutation
{
    use ResolvesActingContext;

    public function create(mixed $rootValue, array $request): CustomerTaxExemption
    {
        $ctx = $this->actingContext();

        return new CreateCustomerTaxExemptionAction(
            $ctx->app,
            $request['input'],
        )->execute();
    }

    public function update(mixed $rootValue, array $request): CustomerTaxExemption
    {
        $ctx = $this->actingContext();

        /** @var CustomerTaxExemption $exemption */
        $exemption = CustomerTaxExemption::getById((int) $request['id'], $ctx->app);

        return new UpdateCustomerTaxExemptionAction(
            $exemption,
            $request['input'],
        )->execute();
    }

    public function delete(mixed $rootValue, array $request): bool
    {
        $ctx = $this->actingContext();

        /** @var CustomerTaxExemption $exemption */
        $exemption = CustomerTaxExemption::getById((int) $request['id'], $ctx->app);
        $exemption->delete();

        return true;
    }
}
