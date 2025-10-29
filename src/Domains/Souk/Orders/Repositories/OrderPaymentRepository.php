<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Repositories;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Orders\Models\Order;

class OrderPaymentRepository
{
    public function __construct(
        protected Apps $app
    ) {
    }

    /**
     * Get orders in period with payment information grouped by date
     */
    public function getOrdersInPeriodWithPayments(
        Carbon $start,
        Carbon $end,
        array $paidStates,
        ?int $variantId = null
    ): Collection {
        return Order::query()
            ->join('order_statuses', 'orders.order_status_id', '=', 'order_statuses.id')
            ->leftJoin('payments', function ($join) {
                $join->on('payments.payable_id', '=', 'orders.id')
                    ->where('payments.payable_type', '=', Order::class);
            })
            ->when($variantId, function ($query) use ($variantId) {
                $query->whereHas('items', function ($q) use ($variantId) {
                    $q->where('variant_id', $variantId);
                });
            })
            ->with(['items'])
            ->whereBetween('orders.created_at', [$start, $end])
            ->where('orders.apps_id', $this->app->id)
            ->whereIn('order_statuses.slug', $paidStates)
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
    }

    /**
     * Get order IDs that match the payment criteria
     */
    public function getOrderIdsByPaymentCriteria(
        Carbon $start,
        Carbon $end,
        array $paidStates
    ): Collection {
        return Order::query()
            ->join('order_statuses', 'orders.order_status_id', '=', 'order_statuses.id')
            ->whereBetween('orders.created_at', [$start, $end])
            ->where('orders.apps_id', $this->app->id)
            ->whereIn('order_statuses.slug', $paidStates)
            ->pluck('orders.id');
    }

    /**
     * Get order items aggregated by variant from commerce DB
     */
    public function getOrderItemStatsByVariant(Collection $orderIds): Collection
    {
        if ($orderIds->isEmpty()) {
            return collect();
        }

        return DB::connection('commerce')
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
    }

    /**
     * Get variant and product information from inventory DB
     */
    public function getVariantsWithProductInfo(
        array $variantIds,
        array $productTypeSlugs = []
    ): Collection {
        if (empty($variantIds)) {
            return collect();
        }

        $variantsQuery = DB::connection('inventory')
            ->table('products_variants')
            ->join('products', 'products.id', '=', 'products_variants.products_id')
            ->whereIn('products_variants.id', $variantIds);

        // Filter by product type slugs (e.g., exclude vehicles)
        if (! empty($productTypeSlugs)) {
            $variantsQuery->join('products_types', 'products_types.id', '=', 'products.products_types_id')
                ->whereIn('products_types.slug', $productTypeSlugs);
        }

        return $variantsQuery
            ->select(
                'products_variants.id as variant_id',
                'products.name as product_name',
                'products_variants.name as variant_name'
            )
            ->get()
            ->keyBy('variant_id');
    }
}
