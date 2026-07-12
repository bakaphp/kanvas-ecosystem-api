<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\DataTransferObject;

use Spatie\LaravelData\Data;

/**
 * One line of an Acumatica sales order (dbo.SOLine, joined to InventoryItem for the SKU).
 * Variant resolution (sku → Kanvas variant) happens in PullSalesOrdersAction.
 */
class AcumaticaImportOrderItem extends Data
{
    public function __construct(
        public readonly string $sku,
        public readonly string $name,
        public readonly float $quantity,
        public readonly int $quantityShipped,
        public readonly float $price,
        public readonly float $discount,
        public readonly float $tax,
        public readonly ?string $warehouse,
    ) {
    }
}
