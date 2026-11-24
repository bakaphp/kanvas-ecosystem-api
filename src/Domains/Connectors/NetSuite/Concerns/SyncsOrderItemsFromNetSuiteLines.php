<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Concerns;

use Exception;
use Kanvas\Souk\Orders\DataTransferObject\OrderItem as OrderItemData;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderItem;

/**
 * Rewrites a Kanvas order's lines to match a NetSuite transaction's item list (quote or sales order):
 * every line is soft-deleted, then each NetSuite line restores-and-updates its row by SKU or adds one.
 */
trait SyncsOrderItemsFromNetSuiteLines
{
    use FindsNetSuiteVariant;
    use ResolvesNetSuiteCurrency;

    /** The `metadata.source` written on order lines this sync adds. */
    abstract protected function netSuiteLineSource(): string;

    protected function markAllOrderItemsAsDeleted(Order $order): void
    {
        $order->items()->where('is_deleted', false)->update(['is_deleted' => true]);
    }

    protected function updateOrderItems(Order $order, array $netsuiteItems): void
    {
        $this->markAllOrderItemsAsDeleted($order);

        foreach ($netsuiteItems as $netsuiteItem) {
            if (! isset($netsuiteItem->item) || ! isset($netsuiteItem->item->name)) {
                continue;
            }

            $sku = $netsuiteItem->item->name;
            $quantity = $netsuiteItem->quantity ?? 0;
            $rate = $netsuiteItem->rate ?? 0;
            $description = $netsuiteItem->description ?? $sku;

            $orderItem = $order->items()->withTrashed()->where('product_sku', $sku)->first();

            if ($orderItem) {
                $this->updateExistingOrderItem(
                    $orderItem,
                    (float) $quantity,
                    (float) $rate,
                    $description
                );
            } else {
                $this->addNewOrderItem(
                    $order,
                    $sku,
                    (float) $quantity,
                    (float) $rate,
                    $description
                );
            }
        }

        $order->calculateTotal();
    }

    protected function updateExistingOrderItem(
        OrderItem $orderItem,
        float $quantity,
        float $rate,
        string $description
    ): void {
        $updated = false;

        if ($orderItem->is_deleted) {
            $orderItem->is_deleted = false;
            $updated = true;
        }

        if ($orderItem->quantity != $quantity) {
            $orderItem->quantity = $quantity;
            $updated = true;
        }

        $currentPrice = $orderItem->unit_price_gross_amount ?? $orderItem->unit_price_net_amount ?? 0;
        if (abs($currentPrice - $rate) > 0.01) {
            $orderItem->unit_price_gross_amount = $rate;
            $orderItem->unit_price_net_amount = $rate;
            $updated = true;
        }

        if ($orderItem->product_name !== $description) {
            $orderItem->product_name = $description;
            $updated = true;
        }

        if ($updated) {
            $orderItem->saveOrFail();
        }
    }

    protected function addNewOrderItem(
        Order $order,
        string $sku,
        float $quantity,
        float $rate,
        string $description
    ): void {
        $variant = $this->findVariantBySku($sku);

        if ($variant?->product === null) {
            report(new Exception("Product with SKU '{$sku}' not found in Kanvas system when adding from NetSuite. Skipping item."));

            return;
        }

        $order->addItem(new OrderItemData(
            app: $this->app,
            variant: $variant,
            name: $variant->name,
            sku: $sku,
            quantity: $quantity,
            price: $rate,
            discount: 0,
            tax: 0,
            currency: $this->getCurrencyModel($order->currency ?? 'USD'),
            metadata: ['source' => $this->netSuiteLineSource()]
        ));
    }
}
