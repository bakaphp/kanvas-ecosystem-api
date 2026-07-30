<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\DataTransferObject;

use Spatie\LaravelData\Data;

/**
 * One line of an Acumatica purchase order (dbo.POLine, joined to InventoryItem for the SKU and to
 * Account/Sub for the line's GL coding). Code → Kanvas id resolution happens in the pull action.
 * accountCd/subCode are null on inventory POs (they hit inventory, not an expense account).
 */
class AcumaticaImportPurchaseOrderLine extends Data
{
    public function __construct(
        public readonly int $lineNumber,
        public readonly ?string $sku,
        public readonly ?string $description,
        public readonly ?string $accountCd,
        public readonly ?string $subCode,
        public readonly float $orderQty,
        public readonly float $openQty,
        public readonly float $receivedQty,
        public readonly float $unitCost,
        public readonly float $extCost,
    ) {
    }
}
