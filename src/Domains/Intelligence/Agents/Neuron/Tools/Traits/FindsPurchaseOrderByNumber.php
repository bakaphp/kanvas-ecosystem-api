<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Kanvas\Scribe\Purchasing\Models\PurchaseOrder;

/**
 * Finds the company's purchase order by its number. Requires HasKanvasContext ($this->app / $this->company)
 * on the same tool.
 */
trait FindsPurchaseOrderByNumber
{
    protected function purchaseOrderByNumber(string $orderNumber): ?PurchaseOrder
    {
        return PurchaseOrder::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->where('order_number', trim($orderNumber))
            ->first();
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
