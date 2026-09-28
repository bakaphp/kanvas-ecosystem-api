<?php

namespace Kanvas\Connectors\Movipass\Actions;

use Baka\Contracts\AppInterface;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Kanvas\Companies\Services\BusinessDayCalculator;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Souk\Orders\DataTransferObject\OrderItem;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;

class GenerateOrderLateFee
{
    public function __construct(
        protected AppInterface $apps,
    ) {
    }

    public function execute(string $timeZonedNow, array $orderIds = [], bool $addLateFee = true): Collection
    {
        // Use SQL to pre-filter orders that are potentially late (at least 1 calendar day)
        $potentiallyLateOrders = Order::query()
            ->fromApp($this->apps)
            ->with('company') // Eager load company for business day calculation
            ->selectRaw("
                TIMESTAMPDIFF(
                DAY,
                JSON_UNQUOTE(JSON_EXTRACT(COALESCE(metadata, '{}'), '$.data.late_fee_grace_start_at')),
                ?
                )
                AS calendar_days_late,
                id,
                metadata,
                companies_id
            ", [$timeZonedNow])
            ->notDeleted()
            ->whereNotFulfilled()
            ->where(
                fn (Builder $query) => $query->whereNull('payment_status')
                    ->orWhere('payment_status', '!=', PaymentStatusEnum::PAID->value)
            )
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

        // Group orders by company to reuse calculator instances
        $ordersByCompany = $potentiallyLateOrders->groupBy('companies_id');

        $lateOrders = collect();
        $now = Carbon::parse($timeZonedNow);

        foreach ($ordersByCompany as $companyId => $companyOrders) {
            // Create calculator once per company instead of per order
            $calculator = new BusinessDayCalculator($companyOrders->first()->company);

            foreach ($companyOrders as $order) {
                $graceStartAt = Carbon::parse(
                    $order->metadata['data']['late_fee_grace_start_at']
                );

                $businessDays = $calculator->calculateBusinessDays($graceStartAt, $now);
                $order->days_late = $businessDays;

                // Only include orders with at least 1 business day late
                if ($order->days_late >= 1) {
                    $lateOrders->push($order);
                }
            }
        }

        if ($addLateFee) {
            $this->addLateFee($lateOrders, $timeZonedNow);
        }

        return $lateOrders;
    }

    public function addLateFee(Collection $orders, string $timeZonedNow): void
    {
        $completeOrders = Order::whereIn('id', $orders->pluck('id'))->get();
        $orders->each(function ($order) use ($completeOrders, $timeZonedNow) {
            $completeOrder = $completeOrders->where('id', $order->id)->first();
            if (! isset($completeOrder->metadata["data"]["late-fee-variant-id"])) {
                return;
            }

            $lateFee = Variants::find($completeOrder->metadata["data"]["late-fee-variant-id"]);
            $lateFeePrice = $lateFee?->getPriceInfoFromDefaultChannel()?->price;

            if (! $lateFeePrice) {
                return;
            }

            // Lock the order row so overlapping runs of the hourly command serialize here.
            // Without it two concurrent runs both pass the "late-fee item already exists?"
            // check and insert duplicate late-fee items with the same created_at second.
            $completeOrder->getConnection()->transaction(function () use ($completeOrder, $lateFee, $lateFeePrice, $order, $timeZonedNow): void {
                Order::query()->whereKey($completeOrder->getKey())->lockForUpdate()->first();

                // FOR UPDATE read so a serialized run sees the item the other run just committed
                // (a plain read would use this transaction's older snapshot and miss it).
                $hasLateFee = $completeOrder->items()->where('variant_id', $lateFee->id)->lockForUpdate()->first();

                // Late fee is a one-time charge: once the order has it, leave it untouched.
                if ($hasLateFee) {
                    return;
                }

                $orderItem = OrderItem::from($this->apps, $completeOrder->company, $completeOrder->region, [
                    'variant_id' => $lateFee->id,
                    'quantity' => 1,
                    'price' => $lateFeePrice,
                ]);

                $completeOrder->addItem($orderItem);

                // Record the latest snapshot every late run so business_days_late stays current,
                // even when the fee quantity itself didn't change.
                $completeOrder->metadata = [
                    "data" => [
                        ...$completeOrder->metadata["data"],
                        "late_fee_charged_at" => $timeZonedNow,
                        "business_days_late" => $order->days_late,
                    ],
                ];
                $completeOrder->calculateTotal(false);

                // Save without updating the updated_at timestamp
                $originalUpdatedAt = $completeOrder->updated_at;
                $completeOrder->timestamps = false;
                $completeOrder->updated_at = $originalUpdatedAt;
                $completeOrder->saveQuietly();
                $completeOrder->timestamps = true;
            });
        });
    }
}
