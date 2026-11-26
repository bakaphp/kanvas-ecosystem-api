<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Purchasing\Actions\Concerns;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\Purchasing\DataTransferObject\PurchaseOrder as PurchaseOrderData;
use Kanvas\Scribe\Purchasing\DataTransferObject\PurchaseOrderLine as PurchaseOrderLineData;
use Kanvas\Scribe\Purchasing\Models\PurchaseOrder;

trait PersistsPurchaseOrderLines
{
    /**
     * Replaces the order's lines with the DTO's and sets the order total from them.
     */
    private function persistLines(PurchaseOrder $order, PurchaseOrderData $data): void
    {
        $order->lines()->delete();

        foreach (array_values($data->lines) as $index => $line) {
            $order->lines()->create([
                'line_number' => $index + 1,
                'sku' => $line->sku,
                'description' => $line->description,
                'inventory_variant_id' => $line->inventory_variant_id,
                'expense_account_id' => $line->expense_account_id,
                'subaccount_id' => $line->subaccount_id,
                'order_qty' => $line->quantity,
                'open_qty' => $line->quantity,
                'received_qty' => 0,
                'unit_cost' => $line->unit_cost,
                'ext_cost' => $line->extendedCost(),
            ]);
        }

        $order->order_total = array_sum(array_map(
            static fn (PurchaseOrderLineData $line): float => $line->extendedCost(),
            $data->lines
        ));
        $order->saveOrFail();
    }

    private function assertLines(PurchaseOrderData $data): void
    {
        foreach ($data->lines as $line) {
            if ($line->quantity <= 0 || $line->unit_cost < 0) {
                throw new ValidationException(
                    "Line \"{$line->description}\" needs a quantity above zero and a cost of zero or more."
                );
            }
        }
    }
}
