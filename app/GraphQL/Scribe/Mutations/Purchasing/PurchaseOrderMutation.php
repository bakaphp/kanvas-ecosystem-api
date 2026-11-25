<?php

declare(strict_types=1);

namespace App\GraphQL\Scribe\Mutations\Purchasing;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\Scribe\Purchasing\Actions\CancelPurchaseOrderAction;
use Kanvas\Scribe\Purchasing\Actions\CreatePurchaseOrderAction;
use Kanvas\Scribe\Purchasing\Actions\UpdatePurchaseOrderAction;
use Kanvas\Scribe\Purchasing\DataTransferObject\PurchaseOrder as PurchaseOrderData;
use Kanvas\Scribe\Purchasing\Models\PurchaseOrder;

class PurchaseOrderMutation
{
    use ResolvesActingContext;

    public function create(mixed $rootValue, array $request): PurchaseOrder
    {
        $ctx = $this->actingContext();

        return new CreatePurchaseOrderAction(
            PurchaseOrderData::fromMultiple(
                $ctx->app,
                $ctx->user,
                $ctx->company,
                $request['input']
            ),
        )->execute();
    }

    public function update(mixed $rootValue, array $request): PurchaseOrder
    {
        $ctx = $this->actingContext();
        $order = PurchaseOrder::getByIdFromCompanyApp((int) $request['id'], $ctx->company, $ctx->app);

        return new UpdatePurchaseOrderAction(
            $order,
            PurchaseOrderData::forUpdate(
                $order,
                $ctx->app,
                $ctx->user,
                $ctx->company,
                $request['input']
            ),
        )->execute();
    }

    public function cancel(mixed $rootValue, array $request): PurchaseOrder
    {
        $ctx = $this->actingContext();
        $order = PurchaseOrder::getByIdFromCompanyApp((int) $request['id'], $ctx->company, $ctx->app);

        return new CancelPurchaseOrderAction($order, $request['reason'] ?? null)->execute();
    }
}
