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

        $activeCount = $this->countOccupied(expirableOnly: true);
        $available = max(0, $maxCapacity - $activeCount);

        return CapacityStats::fromAggregation($maxCapacity, $available);
    }

    /**
     * Count active (occupied) orders for this variant.
     *
     * @param  bool  $expirableOnly  When true, restrict to expirable order types only.
     *                               When false, count across all order types (legacy path).
     */
    public function countOccupied(bool $expirableOnly = false): int
    {
        $candidates = OrderItem::whereHas('order', function ($q) use ($expirableOnly) {
            if ($expirableOnly) {
                $q->whereHas('orderType', fn ($q) => $q->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(config, '$.expirable')) = 'true'"));
            }
            $q->whereNull('parent_id')
              ->whereNotFulfilled()
              ->whereDoesntHave('orderStatus', fn ($q) => $q->where('is_final', true))
              ->where('apps_id', $this->app->getId());
        })
        ->where('variant_id', $this->variant->getId())
        ->with('order')
        ->get();

        $now = Carbon::now();

        return $candidates->filter(function ($item) use ($now) {
            $endAt = data_get($item->order->metadata, 'data.end_at');

            return $endAt === null || Carbon::parse($endAt)->gt($now);
        })->count();
    }
}
