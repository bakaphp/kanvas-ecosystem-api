<?php

namespace Kanvas\Connectors\Movipass\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Collection;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Souk\Orders\DataTransferObject\OrderItem;
use Kanvas\Souk\Orders\Models\Order;

class GenerateOrderLateFee
{
    public function __construct(
        protected AppInterface $apps,
    ) {
    }

    public function execute(string $timeZonedNow, array $orderIds = []): Collection
    {
        $lateOrders = Order::query()
            ->fromApp($this->apps)
            ->selectRaw("
                TIMESTAMPDIFF(
                DAY,
                JSON_UNQUOTE(JSON_EXTRACT(COALESCE(metadata, '{}'), '$.data.late_fee_grace_start_at')),
                ?
                )
                AS days_late,
                id,
                metadata
            ", [$timeZonedNow])
            ->notDeleted()
            ->whereNotFulfilled()
            ->whereNotNull('metadata')
            ->whereRaw("JSON_VALID(metadata)")
            ->whereRaw("JSON_LENGTH(COALESCE(NULLIF(metadata, ''), '{}')) > 0")
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(COALESCE(metadata, '{}'), '$.data.late_fee_grace_start_at')) is not null")
            ->when($orderIds, function ($query) use ($orderIds) {
                $query->whereIn('id', $orderIds);
            })
            ->whereRaw("TIMESTAMPDIFF(
                DAY,
                JSON_UNQUOTE(JSON_EXTRACT(COALESCE(metadata, '{}'), '$.data.late_fee_grace_start_at')),
                ?
            ) >= 1", [$timeZonedNow])
            ->get();

        $this->addLateFee($lateOrders, $timeZonedNow);

        return $lateOrders;
    }

    public function addLateFee(Collection $orders, string $timeZonedNow): void
    {
        $completeOrders = Order::whereIn('id', $orders->pluck('id'))->get();
        $orders->each(function ($order) use ($completeOrders, $timeZonedNow) {
            $completeOrder = $completeOrders->where('id', $order->id)->first();
            $lateFee = Variants::find($completeOrder->metadata["data"]["late_fee_variant_id"]);
            $lateFeePrice = $lateFee->getPriceInfoFromDefaultChannel()->price;

            if ($hasLateFee = $completeOrder->items()->where('variant_id', $lateFee->id)?->first()) {
                $hasLateFee->quantity = $order->days_late;
            } else {
                $orderItem = OrderItem::viaRequest($this->apps, $completeOrder->company, $completeOrder->region, [
                    'variant_id' => $lateFee->id,
                    'quantity' => $order->days_late,
                    'price' => $lateFeePrice,
                ]);

                $completeOrder->addItem($orderItem);
            }

            $completeOrder->metadata = [
                "data" => [
                    ...$completeOrder->metadata["data"],
                    "late_fee_charged_at" => $timeZonedNow,
                ]
            ];

            $completeOrder->calculateTotal();
        });
    }
}
