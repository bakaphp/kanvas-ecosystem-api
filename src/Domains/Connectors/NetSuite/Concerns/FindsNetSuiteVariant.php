<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Concerns;

use Kanvas\Inventory\Variants\Models\Variants;

/**
 * A NetSuite line names its item by `itemId`, which the B2B catalog stores as the barcode and sometimes
 * as the SKU. SKU wins when both match different variants.
 */
trait FindsNetSuiteVariant
{
    protected function findVariantBySku(string $sku): ?Variants
    {
        return Variants::fromApp($this->app)
            ->fromCompany($this->company)
            ->where('sku', $sku)
            ->first()
            ?? Variants::fromApp($this->app)
                ->fromCompany($this->company)
                ->where('barcode', $sku)
                ->first();
    }
}
