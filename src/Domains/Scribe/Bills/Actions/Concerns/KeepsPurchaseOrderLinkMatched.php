<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Bills\Actions\Concerns;

use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Purchasing\Models\PurchaseOrder;
use Kanvas\Scribe\Purchasing\Services\PurchaseOrderMatchService;

trait KeepsPurchaseOrderLinkMatched
{
    /**
     * A bill is linked to an order the company issued only while the three-way match is clean, however the link
     * arrived: on creation, on an edit, or through LinkBillToPurchaseOrderAction. A link that does not match is
     * dropped with a staff note instead of refusing the save, since a bill often arrives before its goods; it is
     * linked again once it matches. An order mirrored from an ERP is reference data and keeps its link.
     */
    private function reconcilePurchaseOrderLink(Bill $bill): void
    {
        if ($bill->purchase_order_id === null) {
            return;
        }

        $order = PurchaseOrder::query()
            ->fromApp($bill->app)
            ->fromCompany($bill->company)
            ->notDeleted()
            ->whereKey($bill->purchase_order_id)
            ->first();

        if ($order === null) {
            $this->unlink($bill, "purchase order #{$bill->purchase_order_id} does not exist for this company");

            return;
        }

        if (! $order->isIssuedByCompany()) {
            return;
        }

        $match = new PurchaseOrderMatchService()->match($order, $bill);

        if (! $match->matched()) {
            $this->unlink(
                $bill,
                "it does not match purchase order {$order->order_number} (" . implode(', ', $match->keys()) . ')'
            );
        }
    }

    /**
     * Two bills of one order take turns. Called before the transaction reads anything else: what the match then
     * reads includes the bill whoever held the order before just linked, where a read taken earlier would miss it.
     */
    private function lockPurchaseOrders(int|string|null ...$ids): void
    {
        $ids = array_unique(array_map('intval', array_filter($ids)));
        sort($ids);

        foreach ($ids as $id) {
            PurchaseOrder::query()->whereKey($id)->lockForUpdate()->value('id');
        }
    }

    private function unlink(Bill $bill, string $reason): void
    {
        $bill->purchase_order_id = null;
        $bill->appendInternalNote("Not linked to a purchase order: {$reason}. Link it once it matches.");
        $bill->save();
    }
}
