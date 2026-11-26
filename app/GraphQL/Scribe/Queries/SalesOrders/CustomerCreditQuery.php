<?php

declare(strict_types=1);

namespace App\GraphQL\Scribe\Queries\SalesOrders;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\SalesOrders\Credit\CustomerCreditService;

class CustomerCreditQuery
{
    use ResolvesActingContext;

    public function credit(mixed $rootValue, array $request): array
    {
        $ctx = $this->actingContext();
        $customer = Organization::getByIdFromCompanyApp((int) $request['customer_id'], $ctx->company, $ctx->app);

        return new CustomerCreditService()->status($customer)->toArray();
    }
}
