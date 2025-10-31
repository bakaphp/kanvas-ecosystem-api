<?php

namespace Kanvas\Souk\Orders\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Orders\Helpers\DateHelper;
use Kanvas\Souk\Orders\Repositories\OrderPaymentRepository;

class GetOrderPaymentStatsAction
{
    protected OrderPaymentRepository $repository;

    public function __construct(
        protected Apps $app,
        protected array $paidStates = ['paid'],
        protected ?int $variantId = null,
        protected array $productTypeSlugs = [],
    ) {
        $this->repository = new OrderPaymentRepository($app);
    }

    public function execute(
        ?string $date = null,
        ?string $startDate = null,
        ?string $endDate = null,
        string $timezone = 'UTC'
    ): array {
        if ($date && (! $startDate || ! $endDate)) {
            $start = Carbon::parse($date, $timezone)->startOfDay()->timezone('UTC');
            $end = Carbon::parse($date, $timezone)->endOfDay()->timezone('UTC');
        } else {
            $start = $startDate ? Carbon::parse($startDate, $timezone)->startOfDay()->timezone('UTC') : now()->startOfDay()->timezone('UTC');
            $end = $endDate ? Carbon::parse($endDate, $timezone)->endOfDay()->timezone('UTC') : now()->endOfDay()->timezone('UTC');
        }

        $currentCount = 0;
        $ordersInPeriod = $this->getOrdersInPeriod($start, $end, $currentCount);
        $currentCount = $ordersInPeriod['count'] ?? 0;

        return [
            'period' => [
                'start' => $start->format('Y-m-d H:i:s'),
                'end' => $end->format('Y-m-d H:i:s'),
            ],
            'ordersInPeriod' => $ordersInPeriod,
            'currentCount' => $currentCount,
        ];
    }

    private function getOrdersInPeriod(Carbon $start, Carbon $end, $currentCount = null): array
    {
        $results = $this->repository->getOrdersInPeriodWithPayments(
            $start,
            $end,
            $this->paidStates,
            $this->variantId
        );

        $daysInRange = collect(DateHelper::generateDateList($start, $end))
            ->map(fn ($date) => trim($date, "'"));

        $groupedResults = $results->keyBy('date');

        $byDates = $daysInRange->map(function ($date) use ($groupedResults) {
            $item = $groupedResults->get($date);
            $total = $item?->total ?? 0;
            $amount = $item?->amount ?? 0;
            $card = $item?->card ?? 0;
            $transaction = $item?->transaction ?? 0;

            return [
                'date' => $date,
                'count' => $total,
                'states' => [
                    'total' => $total,
                    'card' => $card,
                    'amount' => $amount,
                    'transaction' => $transaction,
                    'cardPercentage' => $total > 0 ? round(($card / $total) * 100, 2) : 0,
                    'transactionPercentage' => $total > 0 ? round(($transaction / $total) * 100, 2) : 0,
                ],
            ];
        });

        $totalEntries = $byDates->sum(fn ($entry) => $entry['count'] ?? 0);
        $totalAmount = $byDates->sum(fn ($entry) => $entry['states']['amount'] ?? 0);

        // Get service stats from the already filtered orders
        $byServices = $this->getServiceStatsFromOrders($start, $end, $this->variantId);

        return [
            'orderAvg' => $daysInRange->count() > 0 ? $totalEntries / $daysInRange->count() : 0,
            'count' => $totalEntries,
            'totalAmount' => $totalAmount,
            'byServices' => $byServices,
            'byTransaction' => [
                'card' => $byDates->sum(fn ($entry) => $entry['states']['card'] ?? 0),
                'transfer' => $byDates->sum(fn ($entry) => $entry['states']['transaction'] ?? 0),
            ],
            'data' => $byDates->toArray(),
        ];
    }

    private function getServiceStatsFromOrders($start, $end): array
    {
        // Get order IDs that match our criteria (same filters as main query)
        $orderIds = $this->repository->getOrderIdsByPaymentCriteria(
            $start,
            $end,
            $this->paidStates,
            $this->variantId
        );

        if ($orderIds->isEmpty()) {
            return [];
        }

        // Get order items aggregated by variant (from commerce DB)
        $itemStats = $this->repository->getOrderItemStatsByVariant($orderIds);

        if ($itemStats->isEmpty()) {
            return [];
        }

        // Get variant and product names from inventory DB, filtered by product types if specified
        $variantIds = $itemStats->keys()->toArray();
        $variants = $this->repository->getVariantsWithProductInfo(
            $variantIds,
            $this->productTypeSlugs
        );

        // Merge the stats with variant info
        // Filter out variants that don't match product type criteria
        return $itemStats
            ->filter(function ($item) use ($variants) {
                // Only include if variant exists (and matches product type if filtered)
                return $variants->has($item->variant_id);
            })
            ->map(function ($item) use ($variants) {
                $variant = $variants->get($item->variant_id);
                $productName = $variant->product_name;
                $variantName = $variant->variant_name;

                return [
                    'variantId' => $item->variant_id,
                    'productName' => $productName,
                    'variantName' => $variantName,
                    'serviceName' => $variantName ?: $productName,
                    'orderCount' => (int) $item->order_count,
                    'totalQuantity' => (int) $item->total_quantity,
                    'totalAmount' => (float) $item->total_amount,
                ];
            })
            ->sortByDesc('orderCount')
            ->values()
            ->toArray();
    }
}
