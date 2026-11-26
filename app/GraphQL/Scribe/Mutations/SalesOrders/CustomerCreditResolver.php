<?php

declare(strict_types=1);

namespace App\GraphQL\Scribe\Mutations\SalesOrders;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\SalesOrders\Actions\SetCustomerCreditLimitAction;
use Kanvas\Scribe\SalesOrders\Credit\CustomerCreditService;

class CustomerCreditResolver
{
    use ResolvesActingContext;

    public function credit(mixed $rootValue, array $request): array
    {
        return $this->creditOf($request);
    }

    public function setLimit(mixed $rootValue, array $request): array
    {
        $ctx = $this->actingContext();

        new SetCustomerCreditLimitAction(
            Organization::getByIdFromCompanyApp((int) $request['customer_id'], $ctx->company, $ctx->app),
            isset($request['amount']) ? (float) $request['amount'] : null,
            $request['currency'] ?? null,
        )->execute();

        return $this->creditOf($request);
    }

    private function creditOf(array $request): array
    {
        $ctx = $this->actingContext();

        return new CustomerCreditService()->status(
            $ctx->app,
            $ctx->company,
            Organization::getByIdFromCompanyApp((int) $request['customer_id'], $ctx->company, $ctx->app)
        )->toArray();
    }
}
