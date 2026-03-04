<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Queries\Variants;

use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Souk\Orders\Actions\GetSlotAvailabilityAction;

class VariantSlotAvailabilityResolver
{
    public function resolve(Variants $variant): ?int
    {
        // Check raw warehouse value first — CapacityStats coerces null→0,
        // so we must detect "unlimited" before calling the action.
        // 0 is the DTO default (unconfigured); null means no warehouse record — both = unlimited.
        $rawMaxCapacity = $variant->variantWarehouses()->first()?->max_capacity;

        if (! ($rawMaxCapacity > 0)) {
            return null; // unlimited / unconfigured — no slot tracking
        }

        $data = new GetSlotAvailabilityAction($variant, app(Apps::class))->execute();

        return $data->availableCapacity;
    }
}
