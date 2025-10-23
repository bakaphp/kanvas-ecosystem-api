<?php

namespace Kanvas\Souk\Orders\Actions;

use DateTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Orders\Models\Order;

class GetOrderPaymentStatsAction
{
    public function __construct(
        protected Apps $app,
        protected array $paidStates = ['paid'],
        protected ?int $variantId = null,
        protected array $productTypeSlugs = [],
    ) {
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

        return [
            'period' => [
                'start' => $start->format('Y-m-d H:i:s'),
                'end' => $end->format('Y-m-d H:i:s'),
            ],
            'ordersInPeriod' => $ordersInPeriod,
            'currentCount' => $currentCount,
        ];
    }

    private function getOrdersInPeriod($start, $end, $currentCount = null): array
    {
        $results = Order::query()
            ->join('order_statuses', 'orders.order_status_id', '=', 'order_statuses.id')
            ->leftJoin('payments', function ($join) {
                $join->on('payments.payable_id', '=', 'orders.id')
                    ->where('payments.payable_type', '=', Order::class);
            })
            ->when($this->variantId, function ($query) {
                $query->whereHas('items', function ($q) {
                    $q->where('variants_id', $this->variantId);
                });
            })
            ->whereBetween('orders.created_at', [$start, $end])
            ->where('orders.apps_id', $this->app->id)
            ->whereIn('order_statuses.slug', $this->paidStates)
            ->selectRaw("
                DATE(orders.created_at) AS date,
                COUNT(DISTINCT orders.id) AS total,
                SUM(orders.total_net_amount) AS amount,
                COUNT(DISTINCT payments.id) AS card,
                COUNT(DISTINCT CASE WHEN payments.id IS NULL THEN orders.id END) AS transaction
            ")
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $daysInRange = collect($this->generateDateList($start, $end))
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
        $byServices = $this->variantId === null
            ? $this->getServiceStatsFromOrders($start, $end)
            : [];

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
        $orderIds = Order::query()
            ->join('order_statuses', 'orders.order_status_id', '=', 'order_statuses.id')
            ->whereBetween('orders.created_at', [$start, $end])
            ->where('orders.apps_id', $this->app->id)
            ->whereIn('order_statuses.slug', $this->paidStates)
            ->pluck('orders.id');

        if ($orderIds->isEmpty()) {
            return [];
        }

        // Get order items aggregated by variant (from commerce DB)
        $itemStats = DB::connection('commerce')
            ->table('order_items')
            ->whereIn('order_items.order_id', $orderIds)
            ->where('order_items.is_deleted', 0)
            ->selectRaw('
                order_items.variant_id as variant_id,
                COUNT(DISTINCT order_items.order_id) as order_count,
                SUM(order_items.quantity) as total_quantity,
                SUM(order_items.quantity * order_items.unit_price_net_amount) as total_amount
            ')
            ->groupBy('order_items.variant_id')
            ->get()
            ->keyBy('variant_id');

        if ($itemStats->isEmpty()) {
            return [];
        }

        // Get variant and product names from inventory DB
        // Filter by product types if specified
        $variantIds = $itemStats->keys()->toArray();
        $variantsQuery = DB::connection('inventory')
            ->table('products_variants')
            ->join('products', 'products.id', '=', 'products_variants.products_id')
            ->whereIn('products_variants.id', $variantIds);

        // Filter by product type slugs (e.g., exclude vehicles)
        if (! empty($this->productTypeSlugs)) {
            $variantsQuery->join('products_types', 'products_types.id', '=', 'products.products_types_id')
                ->whereIn('products_types.slug', $this->productTypeSlugs);
        }

        $variants = $variantsQuery
            ->select(
                'products_variants.id as variant_id',
                'products.name as product_name',
                'products_variants.name as variant_name'
            )
            ->get()
            ->keyBy('variant_id');

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

    private function generateDateList($start, $end): array
    {
        $dates = [];
        $startDate = new DateTime($start);
        $endDate = new DateTime($end);

        while ($startDate <= $endDate) {
            $dates[] = "'" . $startDate->format('Y-m-d') . "'";
            $startDate->modify('+1 day');
        }

        return $dates;
    }
}
