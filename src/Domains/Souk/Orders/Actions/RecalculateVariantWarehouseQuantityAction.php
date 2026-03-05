<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Actions;

use Baka\Contracts\AppInterface;
use Kanvas\Souk\Orders\Models\Order;

class RecalculateVariantWarehouseQuantityAction
{
    public function __construct(
        protected readonly Order $order,
        protected readonly AppInterface $app,
    ) {
    }

    public function execute(): void
    {
        if (count($this->order->items) === 0 || $this->order->parent_id) {
            return;
        }

        $variant = $this->order->items->first(function ($item) {
            $hasCapacityAttribute = $item->variant->product?->attributes
                ->contains(fn ($attribute) => in_array($attribute->slug, ['capacity', 'slots']) && ! empty($attribute->value));

            return $hasCapacityAttribute || ($item->variant->variantWarehouses()->first()?->max_capacity > 0);
        })?->variant;

        if (! $variant) {
            return;
        }

        $product = $variant->product;

        if ($this->order->orderType?->isExpirable()) {
            $allVariants = $product->variants()->get();
            $totalMax       = 0;
            $totalOccupied  = 0;
            $totalAvailable = 0;
            $currentVariantSlotData  = null;
            $currentVariantWarehouse = null;

            foreach ($allVariants as $v) {
                $variantWarehouseRecord = $v->variantWarehouses()->first();
                if (! ($variantWarehouseRecord?->max_capacity > 0)) {
                    continue;
                }

                $vData = new GetSlotAvailabilityAction($v, $this->app)->execute();
                $totalMax       += $vData->maxCapacity;
                $totalOccupied  += $vData->occupiedCapacity;
                $totalAvailable += $vData->availableCapacity;

                if ($v->getId() === $variant->getId()) {
                    $currentVariantSlotData  = $vData;
                    $currentVariantWarehouse = $variantWarehouseRecord->warehouse ?? null;
                }
            }

            if ($currentVariantSlotData !== null && $currentVariantWarehouse !== null) {
                $variant->updateQuantityInWarehouse($currentVariantWarehouse, $currentVariantSlotData->availableCapacity);
            }

            $product->addAttribute('capacity', [
                'occupiedParkingSpaces'  => $totalOccupied,
                'availableParkingSpaces' => $totalAvailable,
                'totalParkingSpaces'     => $totalMax,
            ]);
        } else {
            $channel = $variant->variantChannels()->first();
            $capacity = $product->getAttributeByName('capacity')?->value;
            $legacySlots = $capacity['occupiedParkingSpaces'] ?? null;
            $newSlots = $product->getAttributeByName('slots')?->value;
            $slots = $newSlots ?? $legacySlots;
            $variantWarehouse = $channel?->productVariantWarehouse()->first();

            $activeOrders = new GetSlotAvailabilityAction($variant, $this->app)->countOccupied();
            $available = $slots - $activeOrders;
            $variant->updateQuantityInWarehouse($variantWarehouse->warehouse, $available);
            $product->addAttribute('capacity', [
                'occupiedParkingSpaces'  => $activeOrders,
                'availableParkingSpaces' => $available,
                'totalParkingSpaces'     => $slots,
            ]);
            $product->addAttribute('slots', $slots);
            $product->save();
        }
    }
}
