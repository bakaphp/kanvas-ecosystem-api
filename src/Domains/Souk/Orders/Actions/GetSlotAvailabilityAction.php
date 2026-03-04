<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Carbon;
use Kanvas\Inventory\Stats\DataTransferObject\CapacityStats;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Souk\Orders\Models\OrderItem;

class GetSlotAvailabilityAction
{
    public function __construct(
        protected readonly Variants $variant,
        protected readonly AppInterface $app,
    ) {
    }

    public function execute(): CapacityStats
    {
        $variantWarehouse = $this->variant->variantWarehouses()->first();
        $maxCapacity = $variantWarehouse?->max_capacity;

        // 0 is the DTO default (unconfigured) and null means no warehouse record — both mean "unlimited"
        if (! ($maxCapacity > 0)) {
            return CapacityStats::fromAggregation(null, null);
        }

        // Fetch candidate active orders — filter end_at in PHP to avoid JSON string-cast issues.
        // Active = expirable order type + not fulfilled + not completed/cancelled
        $candidates = OrderItem::whereHas('order', function ($q) {
            $q->whereHas('orderType', fn ($q) => $q->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(config, '$.expirable')) = 'true'"))
              ->whereNotFulfilled()
              ->whereNotIn('status', ['completed', 'cancelled'])
              ->where('apps_id', $this->app->getId());
        })
        ->where('variant_id', $this->variant->getId())
        ->with('order')
        ->get();

        $now = Carbon::now();
        $activeCount = $candidates->filter(function ($item) use ($now) {
            $endAt = data_get($item->order->metadata, 'data.end_at');

            return $endAt === null || Carbon::parse($endAt)->gt($now);
        })->count();

        $available = max(0, $maxCapacity - $activeCount);

        return CapacityStats::fromAggregation($maxCapacity, $available);
    }
}
