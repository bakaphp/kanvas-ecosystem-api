<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Sales;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Souk\Orders\Models\OrderItem;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * Top products by revenue (and units sold) over booked order lines, optionally bounded by a date
 * range. Read-only aggregation over Souk order items joined to their orders — no ERP calls.
 */
#[AgentTool(name: 'Sales By Product', category: 'commerce')]
class SalesByProductTool extends Tool
{
    use HasKanvasContext;

    private const EXCLUDED_STATUSES = ['draft', 'canceled', 'cancelled', 'failed'];

    public function __construct()
    {
        parent::__construct(
            name: 'sales_by_product',
            description: 'Ranks products/SKUs by revenue and units sold over booked orders (draft/canceled '
                . 'excluded). Optionally bounded by since/until dates. Use for "best sellers", "top products this '
                . 'month", "how many units of SKU X did we sell".',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(name: 'limit', type: PropertyType::INTEGER, description: 'Max products to return. Default 10, max 50.', required: false),
            new ToolProperty(name: 'since', type: PropertyType::STRING, description: 'Lower-bound order date, ISO YYYY-MM-DD. Omit for all-time.', required: false),
            new ToolProperty(name: 'until', type: PropertyType::STRING, description: 'Upper-bound order date, ISO YYYY-MM-DD. Omit for open-ended.', required: false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?int $limit = null, ?string $since = null, ?string $until = null): array
    {
        $limit = max(1, min(50, $limit ?? 10));

        $rows = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.apps_id', $this->app->getId())
            ->where('orders.companies_id', $this->company->getId())
            ->where('orders.is_deleted', false)
            ->whereNotIn('orders.status', self::EXCLUDED_STATUSES)
            ->where('order_items.is_public', 1)
            ->when($since !== null && $since !== '', fn ($q) => $q->whereDate('orders.created_at', '>=', $since))
            ->when($until !== null && $until !== '', fn ($q) => $q->whereDate('orders.created_at', '<=', $until))
            ->selectRaw(
                'order_items.product_sku, MAX(order_items.product_name) as product, '
                . 'SUM(order_items.quantity) as units, '
                . 'SUM(order_items.quantity * order_items.unit_price_gross_amount) as revenue'
            )
            ->groupBy('order_items.product_sku')
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get();

        return [
            'count' => $rows->count(),
            'products' => $rows->map(fn ($r): array => [
                'sku' => $r->product_sku,
                'product' => $r->product,
                'units' => round((float) $r->units, 2),
                'revenue' => round((float) $r->revenue, 2),
            ])->all(),
        ];
    }
}
