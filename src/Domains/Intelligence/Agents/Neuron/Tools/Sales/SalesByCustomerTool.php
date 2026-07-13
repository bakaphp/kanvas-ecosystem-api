<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Sales;

use Kanvas\Guild\Customers\Models\People;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Souk\Orders\Models\Order;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * Top customers by revenue over booked sales orders (excludes draft/canceled/failed), optionally
 * bounded by a date range. Read-only aggregation over Souk orders — no ERP calls.
 */
#[AgentTool(name: 'Sales By Customer')]
class SalesByCustomerTool extends Tool
{
    use HasKanvasContext;

    private const EXCLUDED_STATUSES = ['draft', 'canceled', 'cancelled', 'failed'];

    public function __construct()
    {
        parent::__construct(
            name: 'sales_by_customer',
            description: 'Ranks customers by total revenue over their booked orders (draft/canceled excluded). '
                . 'Optionally bounded by since/until dates. Use for "who are our biggest customers", "top buyers '
                . 'this quarter", "how much has customer X spent".',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(name: 'limit', type: PropertyType::INTEGER, description: 'Max customers to return. Default 10, max 50.', required: false),
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

        $rows = Order::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('is_deleted', false)
            ->whereNotIn('status', self::EXCLUDED_STATUSES)
            ->whereNotNull('people_id')
            ->when($since !== null && $since !== '', fn ($q) => $q->whereDate('created_at', '>=', $since))
            ->when($until !== null && $until !== '', fn ($q) => $q->whereDate('created_at', '<=', $until))
            ->selectRaw('people_id, COUNT(*) as orders, SUM(total_gross_amount) as revenue')
            ->groupBy('people_id')
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get();

        $people = People::query()->whereIn('id', $rows->pluck('people_id'))->get()->keyBy('id');

        return [
            'count' => $rows->count(),
            'customers' => $rows->map(function ($r) use ($people): array {
                $p = $people->get($r->people_id);
                $name = $p !== null ? trim($p->firstname . ' ' . $p->lastname) : '';

                return [
                    'customer' => $name !== '' ? $name : ('people #' . $r->people_id),
                    'orders' => (int) $r->orders,
                    'revenue' => round((float) $r->revenue, 2),
                ];
            })->all(),
        ];
    }
}
