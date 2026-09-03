<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Sales;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ReportsToolOutcome;
use Kanvas\Souk\Orders\Models\Order;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * Total sales revenue over booked orders (excludes draft/canceled/failed), with an optional
 * month-by-month breakdown and date bounds. Read-only aggregation over Souk orders — no ERP calls.
 */
#[AgentTool(name: 'Sales Revenue', category: 'commerce')]
class SalesRevenueTool extends Tool
{
    use HasKanvasContext;
    use ReportsToolOutcome;

    private const EXCLUDED_STATUSES = ['draft', 'canceled', 'cancelled', 'failed'];

    public function __construct()
    {
        parent::__construct(
            name: 'sales_revenue',
            description: 'Total booked sales revenue + order count, optionally bounded by since/until dates and '
                . 'optionally broken down by month. Use for "revenue this quarter", "how are sales trending", '
                . '"total sales this year", "revenue this month vs last".',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(name: 'since', type: PropertyType::STRING, description: 'Lower-bound order date, ISO YYYY-MM-DD. Omit for all-time.', required: false),
            new ToolProperty(name: 'until', type: PropertyType::STRING, description: 'Upper-bound order date, ISO YYYY-MM-DD. Omit for open-ended.', required: false),
            new ToolProperty(name: 'by_month', type: PropertyType::BOOLEAN, description: 'Include a month-by-month breakdown. Default false (total only).', required: false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?string $since = null, ?string $until = null, ?bool $by_month = null): array
    {
        $base = fn (): Builder => Order::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('is_deleted', false)
            ->whereNotIn('status', self::EXCLUDED_STATUSES)
            ->when($since !== null && $since !== '', fn ($q) => $q->whereDate('created_at', '>=', $since))
            ->when($until !== null && $until !== '', fn ($q) => $q->whereDate('created_at', '<=', $until));

        $orders = $base()->count();

        $result = [
            'since' => $since !== null && $since !== '' ? $since : 'all-time',
            'until' => $until !== null && $until !== '' ? $until : 'open-ended',
            'total_revenue' => round((float) $base()->sum('total_gross_amount'), 2),
            'orders' => $orders,
        ];

        // A bare {revenue: 0, orders: 0} reads to the model as "the call didn't work", and it retries
        // the identical arguments until Neuron kills the turn (Sentry KANVAS-ECOSYSTEM-682). NOOP says
        // the zero is final; the override adds the one thing generic guidance can't know — where this
        // company's data actually starts and ends, so a corrected call is possible.
        if ($orders === 0) {
            $result += $this->bookedOrderDateBounds();

            return $this->noop(
                $result,
                'Zero is the complete, correct answer for this range — no booked orders fall in it. A range '
                    . 'inside first_booked_order_date..last_booked_order_date would return data.',
            );
        }

        if ($by_month === true) {
            $result['by_month'] = $base()
                ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month, SUM(total_gross_amount) as revenue, COUNT(*) as orders")
                ->groupBy('month')
                ->orderBy('month')
                ->get()
                ->map(fn ($r): array => [
                    'month' => $r->month,
                    'revenue' => round((float) $r->revenue, 2),
                    'orders' => (int) $r->orders,
                ])->all();
        }

        return $result;
    }

    /**
     * The window the tenant actually has booked orders in, so a model that queried an empty range can
     * correct itself in one call instead of probing dates. Both null when the tenant has no orders at all.
     *
     * @return array{first_booked_order_date: string|null, last_booked_order_date: string|null}
     */
    private function bookedOrderDateBounds(): array
    {
        $bounds = Order::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('is_deleted', false)
            ->whereNotIn('status', self::EXCLUDED_STATUSES)
            ->selectRaw('MIN(created_at) as first_at, MAX(created_at) as last_at')
            ->first();

        return [
            'first_booked_order_date' => $bounds?->first_at !== null ? substr((string) $bounds->first_at, 0, 10) : null,
            'last_booked_order_date' => $bounds?->last_at !== null ? substr((string) $bounds->last_at, 0, 10) : null,
        ];
    }
}
