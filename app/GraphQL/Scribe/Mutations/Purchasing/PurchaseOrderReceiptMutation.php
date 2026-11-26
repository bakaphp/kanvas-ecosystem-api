<?php

declare(strict_types=1);

namespace App\GraphQL\Scribe\Mutations\Purchasing;

use App\GraphQL\Concerns\ResolvesActingContext;
use Illuminate\Support\Carbon;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Purchasing\Actions\LinkBillToPurchaseOrderAction;
use Kanvas\Scribe\Purchasing\Actions\ReceivePurchaseOrderAction;
use Kanvas\Scribe\Purchasing\Models\PurchaseOrder;
use Kanvas\Scribe\Purchasing\Models\PurchaseOrderReceipt;

class PurchaseOrderReceiptMutation
{
    use ResolvesActingContext;

    public function receive(mixed $rootValue, array $request): PurchaseOrderReceipt
    {
        return new ReceivePurchaseOrderAction(
            order: $this->order($request['id']),
            user: $this->actingContext()->user,
            receivedOn: Carbon::parse($request['received_on']),
            lines: $request['lines'],
            reference: $request['reference'] ?? null,
            notes: $request['notes'] ?? null,
        )->execute();
    }

    public function link(mixed $rootValue, array $request): Bill
    {
        return new LinkBillToPurchaseOrderAction(
            $this->bill($request['bill_id']),
            $this->order($request['purchase_order_id'])
        )->execute();
    }

    private function order(int|string $id): PurchaseOrder
    {
        $ctx = $this->actingContext();

        return PurchaseOrder::getByIdFromCompanyApp((int) $id, $ctx->company, $ctx->app);
    }

    private function bill(int|string $id): Bill
    {
        $ctx = $this->actingContext();

        return Bill::getByIdFromCompanyApp((int) $id, $ctx->company, $ctx->app);
    }
}
