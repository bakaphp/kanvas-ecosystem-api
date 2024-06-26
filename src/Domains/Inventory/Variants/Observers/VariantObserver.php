<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Variants\Observers;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Variants\Models\Variants;

class VariantObserver
{
    public function deleting(Variants $variant): void
    {
        $totalVariant = Variants::where('companies_id', $variant->companies_id)->count();

        if ($totalVariant === 1 && ! $variant->is_deleted) {
            throw new ValidationException('There must be at least one variant for each product.');
        }
    }
}
