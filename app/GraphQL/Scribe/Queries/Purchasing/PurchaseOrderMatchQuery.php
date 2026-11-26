<?php

declare(strict_types=1);

namespace App\GraphQL\Scribe\Queries\Purchasing;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Purchasing\Models\PurchaseOrder;
use Kanvas\Scribe\Purchasing\Services\PurchaseOrderMatchService;

class PurchaseOrderMatchQuery
{
    use ResolvesActingContext;

    public function match(mixed $rootValue, array $request): array
    {
        $ctx = $this->actingContext();

        return new PurchaseOrderMatchService()->match(
            PurchaseOrder::getByIdFromCompanyApp((int) $request['purchase_order_id'], $ctx->company, $ctx->app),
            Bill::getByIdFromCompanyApp((int) $request['bill_id'], $ctx->company, $ctx->app)
        )->toArray();
    }
}
