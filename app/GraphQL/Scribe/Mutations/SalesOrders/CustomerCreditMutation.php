<?php

declare(strict_types=1);

namespace App\GraphQL\Scribe\Mutations\SalesOrders;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\SalesOrders\Actions\SetCustomerCreditLimitAction;
use Kanvas\Scribe\SalesOrders\Credit\CustomerCreditService;

class CustomerCreditMutation
{
    use ResolvesActingContext;

    public function setLimit(mixed $rootValue, array $request): array
    {
        $ctx = $this->actingContext();
        $customer = Organization::getByIdFromCompanyApp((int) $request['customer_id'], $ctx->company, $ctx->app);

        new SetCustomerCreditLimitAction(
            $customer,
            isset($request['amount']) ? (float) $request['amount'] : null,
            $request['currency'] ?? null,
        )->execute();

        return new CustomerCreditService()->status($customer)->toArray();
    }
}
