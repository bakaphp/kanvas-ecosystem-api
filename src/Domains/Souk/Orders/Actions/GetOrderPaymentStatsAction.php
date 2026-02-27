<?php

namespace Kanvas\Souk\Orders\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Orders\Helpers\DateHelper;
use Kanvas\Souk\Orders\Repositories\OrderPaymentRepository;

class GetOrderPaymentStatsAction
{
    protected OrderPaymentRepository $repository;
    protected array $productVariantIds = [];

    public function __construct(
        protected Apps $app,
        protected array $paidStates = ['paid'],
        protected ?int $variantId = null,
        protected array $productTypeSlugs = [],
        protected array $orderTypeNames = [],
        protected array $providers = [],
        protected ?int $productId = null,
    ) {
        $this->repository = new OrderPaymentRepository($app);

        if ($this->productId && ! $this->variantId) {
            $this->productVariantIds = DB::connection('inventory')
                ->table('products_variants')
                ->where('products_id', $this->productId)
                ->pluck('id')
                ->toArray();
        }
    }

    private const ALL_PERIODS = ['DAY', 'WEEK', 'MONTH', 'YEAR'];

    public function execute(
        ?string $date = null,
        ?string $startDate = null,
        ?string $endDate = null,
        string $timezone = 'UTC',
        ?array $groupPeriods = null,
        string $periodBreakdown = 'MONTH',
    ): array {
        if ($date && (! $startDate || ! $endDate)) {
            $start = Carbon::parse($date, $timezone)->startOfDay()->timezone('UTC');
            $end = Carbon::parse($date, $timezone)->endOfDay()->timezone('UTC');
        } else {
            $start = $startDate ? Carbon::parse($startDate, $timezone)->startOfDay()->timezone('UTC') : now()->startOfDay()->timezone('UTC');
            $end = $endDate ? Carbon::parse($endDate, $timezone)->endOfDay()->timezone('UTC') : now()->endOfDay()->timezone('UTC');
        }

        $groupPeriods ??= self::ALL_PERIODS;

        $currentCount = 0;
        $ordersInPeriod = $this->getOrdersInPeriod($start, $end, $currentCount, $timezone);
        $currentCount = $ordersInPeriod['count'] ?? 0;

        return [
            'period' => [
                'start' => $start->copy()->timezone($timezone)->format('Y-m-d H:i:s'),
                'end' => $end->copy()->timezone($timezone)->format('Y-m-d H:i:s'),
            ],
            'ordersInPeriod' => $ordersInPeriod,
            'currentCount' => $currentCount,
            'byPeriod' => $this->getByPeriod($periodBreakdown, $start, $end, $timezone),
            'periods' => $this->computePeriods($groupPeriods, $start, $end, $currentCount, (float) ($ordersInPeriod['totalAmount'] ?? 0)),
        ];
    }

    private function getOrdersInPeriod(Carbon $start, Carbon $end, $currentCount = null, string $timezone = 'UTC'): array
    {
        $results = $this->repository->getOrdersInPeriodWithPayments(
            $start,
            $end,
            $this->paidStates,
            $this->variantId,
            $timezone,
            $this->orderTypeNames,
            $this->productVariantIds
        );

        $daysInRange = collect(DateHelper::generateDateList($start, $end, $timezone))
            ->map(fn ($date) => trim($date, "'"));

        $groupedResults = $results->keyBy('date');

        $byDates = $daysInRange->map(function ($date) use ($groupedResults) {
            $item = $groupedResults->get($date);
            $total = $item?->total ?? 0;
            $amount = $item?->amount ?? 0;
            $card = $item?->card ?? 0;
            $transaction = $item?->transaction ?? 0;
            $cardAmount = $item?->card_amount ?? 0;
            $transactionAmount = $item?->transaction_amount ?? 0;

            return [
                'date' => $date,
                'count' => $total,
                'states' => [
                    'total' => $total,
                    'card' => $card,
                    'amount' => $amount,
                    'transaction' => $transaction,
                    'card_amount' => $cardAmount,
                    'transaction_amount' => $transactionAmount,
                    'cardPercentage' => $total > 0 ? round(($card / $total) * 100, 2) : 0,
                    'transactionPercentage' => $total > 0 ? round(($transaction / $total) * 100, 2) : 0,
                ],
            ];
        });

        $totalEntries = $byDates->sum(fn ($entry) => $entry['count'] ?? 0);
        $totalAmount = $byDates->sum(fn ($entry) => $entry['states']['amount'] ?? 0);

        // Get service stats from the already filtered orders
        $byServices = $this->getServiceStatsFromOrders($start, $end, $this->variantId);

        // Get provider stats if providers are specified
        $byProvider = [];
        if (! empty($this->providers)) {
            $providerResults = $this->repository->getOrdersByProvider(
                $start,
                $end,
                $this->paidStates,
                $this->providers,
                $this->variantId,
                $this->productVariantIds
            );
            $byProvider = $providerResults->map(fn ($row) => [
                'name' => $row->provider_name,
                'count' => (int) $row->total_count,
                'totalAmount' => (float) $row->total_amount,
            ])->values()->toArray();
        }

        return [
            'orderAvg' => $daysInRange->count() > 0 ? $totalEntries / $daysInRange->count() : 0,
            'count' => $totalEntries,
            'totalAmount' => $totalAmount,
            'byServices' => $byServices,
            'byProvider' => $byProvider,
            'byTransaction' => [
                'card' => $byDates->sum(fn ($entry) => $entry['states']['card'] ?? 0),
                'transfer' => $byDates->sum(fn ($entry) => $entry['states']['transaction'] ?? 0),
                'cardAmount' => $byDates->sum(fn ($entry) => $entry['states']['card_amount'] ?? 0),
                'transferAmount' => $byDates->sum(fn ($entry) => $entry['states']['transaction_amount'] ?? 0),
            ],
            'data' => $byDates->toArray(),
        ];
    }

    private function getByPeriod(string $periodType, Carbon $start, Carbon $end, string $timezone): array
    {
        $rows = $this->repository->getBreakdownByPeriod(
            $periodType,
            $start,
            $end,
            $this->paidStates,
            $timezone,
            $this->orderTypeNames,
            $this->variantId,
            $this->productVariantIds,
        );

        return $rows->map(fn ($row) => [
            'label'              => $row->label,
            'total_transactions' => (int) $row->total_transactions,
            'total_amount'       => (float) $row->total_amount,
        ])->values()->toArray();
    }

    private function computePeriods(array $groupPeriods, Carbon $start, Carbon $end, int $count, float $total): array
    {
        $days = max((int) $start->diffInDays($end) + 1, 1);
        $periods = [];

        foreach ($groupPeriods as $type) {
            $periodsInRange = match (strtoupper($type)) {
                'DAY'   => $days,
                'WEEK'  => max((int) ceil($days / 7.0), 1),
                'MONTH' => max($start->copy()->startOfMonth()->diffInMonths($end->copy()->startOfMonth()) + 1, 1),
                'YEAR'  => max($start->copy()->startOfYear()->diffInYears($end->copy()->startOfYear()) + 1, 1),
                default => $days,
            };

            $periods[] = [
                'period_type'      => strtoupper($type),
                'count'            => $count,
                'avg_count'        => $periodsInRange > 0 ? round($count / $periodsInRange, 2) : 0,
                'total'            => $total,
                'amount_avg'       => $periodsInRange > 0 ? round($total / $periodsInRange, 2) : 0,
                'periods_in_range' => (int) $periodsInRange,
            ];
        }

        return $periods;
    }

    private function getServiceStatsFromOrders($start, $end): array
    {
        // Get order IDs that match our criteria (same filters as main query)
        $orderIds = $this->repository->getOrderIdsByPaymentCriteria(
            $start,
            $end,
            $this->paidStates,
            $this->variantId,
            $this->orderTypeNames,
            $this->productVariantIds
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
