<?php

declare(strict_types=1);

namespace Kanvas\Scribe\SalesOrders\Actions\Concerns;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\SalesOrders\DataTransferObject\SalesOrder as SalesOrderData;
use Kanvas\Scribe\SalesOrders\DataTransferObject\SalesOrderLine as SalesOrderLineData;
use Kanvas\Scribe\SalesOrders\Models\SalesOrder;

trait PersistsSalesOrderLines
{
    /**
     * Replaces the order's lines with the DTO's and sets the order total from them.
     */
    private function persistLines(SalesOrder $order, SalesOrderData $data): void
    {
        $order->lines()->delete();

        foreach (array_values($data->lines) as $index => $line) {
            $order->lines()->create([
                'line_number' => $index + 1,
                'sku' => $line->sku,
                'description' => $line->description,
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_price,
                'line_total' => $line->total(),
            ]);
        }

        $order->order_total = array_sum(array_map(
            static fn (SalesOrderLineData $line): float => $line->total(),
            $data->lines
        ));
        $order->saveOrFail();
    }

    private function assertLines(SalesOrderData $data): void
    {
        foreach ($data->lines as $line) {
            if ($line->quantity <= 0 || $line->unit_price < 0) {
                throw new ValidationException(
                    "Line \"{$line->description}\" needs a quantity above zero and a price of zero or more."
                );
            }
        }
    }
}
