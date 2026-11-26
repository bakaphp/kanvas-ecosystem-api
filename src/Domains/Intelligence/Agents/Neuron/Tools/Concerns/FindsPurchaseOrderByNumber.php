<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Concerns;

use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\FindsTenantRecordForTool;
use Kanvas\Scribe\Purchasing\Models\PurchaseOrder;

/**
 * Finds the company's purchase order by its number. Requires HasKanvasContext ($this->app / $this->company)
 * on the same tool.
 */
trait FindsPurchaseOrderByNumber
{
    use FindsTenantRecordForTool;

    protected function purchaseOrderByNumber(string $orderNumber): ?PurchaseOrder
    {
        $order = $this->findTenantRecordOrNotFound(
            PurchaseOrder::class,
            'order_number',
            $orderNumber,
            'purchase order'
        );

        return $order instanceof PurchaseOrder ? $order : null;
    }

    /**
     * @return array{found: false, reason: string, message: string}
     */
    protected function purchaseOrderNotFound(string $orderNumber): array
    {
        return [
            'found' => false,
            'reason' => 'purchase_order_not_found',
            'message' => "No purchase order numbered {$orderNumber} for this company.",
        ];
    }
}
