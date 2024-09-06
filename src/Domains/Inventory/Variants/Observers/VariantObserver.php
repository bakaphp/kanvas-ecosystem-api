<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Variants\Observers;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Variants\Models\Variants;

class VariantObserver
{
    public function saved(Variants $variant): void
    {
        $variant->clearLightHouseCacheJob();
    }

    public function deleting(Variants $variant): void
    {
        if ($variant->isLastVariant()) {
            throw new ValidationException('Can\'t delete, you have to have at least one Variant per product');
        }
    }
}
