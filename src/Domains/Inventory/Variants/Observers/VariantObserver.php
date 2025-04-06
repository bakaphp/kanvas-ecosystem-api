<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Variants\Observers;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Variants\Models\Variants;

class VariantObserver
{
    public function saved(Variants $variant): void
    {
        $variant->clearLightHouseCache(withKanvasConfiguration: false);
    }

    public function deleting(Variants $variant): void
    {
        $totalVariant = Variants::fromCompany($variant->company)
        ->fromApp($variant->app)
        ->where('products_id', $variant->products_id)
        ->count();

        if ($totalVariant === 1 && (int) $variant->is_deleted !== 0) {
            throw new ValidationException('There must be at least one variant for each product.');
        }
    }
}
