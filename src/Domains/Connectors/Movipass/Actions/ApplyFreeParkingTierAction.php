<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Connectors\Movipass\Enums\ProductAttributeEnum;
use Kanvas\Souk\Orders\Models\Order;

class ApplyFreeParkingTierAction
{
    public function __construct(
        protected readonly Order $order,
    ) {
    }

    public function execute(): bool
    {
        if ($this->order->parent_id) {
            return false;
        }

        $freeMinutes = $this->resolveFreeMinutes();

        if ($freeMinutes === null) {
            return false;
        }

        $startAt = Carbon::parse($this->order->metadata['data']['start_at'] ?? 'now');

        $this->order->metadata = [
            ...$this->order->metadata ?? [],
            'data' => [
                ...$this->order->metadata['data'] ?? [],
                'start_at' => $startAt->toDateTimeString(),
                // Derived here, never taken from the payload: the client controls the order total,
                // so a client-supplied duration would hand out unlimited free parking.
                'end_at' => $startAt->copy()->addMinutes($freeMinutes)->toDateTimeString(),
                'free_tier' => true,
                'free_minutes' => $freeMinutes,
            ],
        ];

        $this->zeroOutAmounts();

        $this->order->saveQuietly();

        return true;
    }

    private function resolveFreeMinutes(): ?int
    {
        foreach ($this->order->items as $item) {
            $attribute = $item->variant?->product?->getAttributeByName(ProductAttributeEnum::PARKING_FREE_MINUTES->value);

            if ((int) $attribute?->value > 0) {
                return (int) $attribute->value;
            }
        }

        return null;
    }

    /**
     * Items are zeroed alongside the order totals so Order::calculateTotal(), which sums
     * unit_price_net_amount × quantity, cannot resurrect a charge on a free reservation.
     */
    private function zeroOutAmounts(): void
    {
        $this->order->items()->update([
            'unit_price_net_amount' => 0,
            'unit_price_gross_amount' => 0,
        ]);

        $this->order->total_gross_amount = 0;
        $this->order->total_net_amount = 0;
        $this->order->discount_amount = 0;
        $this->order->tax_amount = 0;
    }
}
