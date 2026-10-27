<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Souk;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsRepeatCalls;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\RunsOrderReports;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use Kanvas\Souk\Orders\Services\OrderReportService;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

#[AgentTool(name: 'Order Trend', category: 'commerce')]
class OrderTrendTool extends Tool
{
    use GuardsRepeatCalls;
    use HasKanvasContext;
    use ReportsToolOutcome;
    use RunsOrderReports;
    use TrackByInputs;

    protected string $name = 'order_trend';

    protected ?string $description = 'Order count and revenue over time, bucketed by day, week or month, with the per-period '
        . 'averages plus the busiest and slowest period in the range. Use for "how are orders trending", '
        . '"revenue month by month", "which week was our best", "is volume going up or down", "all-time high '
        . 'month" (read peak_gross_revenue_period). Filter by order_types and/or tags (both from '
        . 'list_order_types); a tag such as "corporativo" is how a customer segment is marked on orders. '
        . 'Returns one row per period that actually has orders — periods with none are omitted, not '
        . 'zero-filled. Each row also carries new_customers (customers whose FIRST order within the selected '
        . 'order_types/tags falls in that period, computed over the whole history, so pass order_types to get '
        . '"new to this vertical") and new_to_company (the subset of those with no earlier order in ANY order '
        . 'type: "never bought from us before"). A customer is one people_id, else users_id, else email; '
        . 'orders_without_customer counts orders that carry none and are left out of both. Use these for "new '
        . 'users this week", "first-time buyers", "nuevos en la vertical" — never compute cohorts from '
        . 'export_records. An unknown order type or tag comes back as invalid_args listing the valid names: '
        . 'pick one of those, never retry a spelling. For a single total instead of a series use sales_revenue '
        . 'or order_payment_stats. Set date_anchor to "paid" to bucket by the date each order was paid instead '
        . 'of the date it was created, and pass timezone so days are cut in local time instead of UTC.';

    public function __construct()
    {
        $this->initRepeatGuard();
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(name: 'group_by', type: PropertyType::STRING, description: 'Bucket size: "day", "week" (weeks start Monday) or "month" (default).', required: false),
            new ToolProperty(name: 'order_types', type: PropertyType::STRING, description: 'Optional comma-separated order-type names to restrict to (see list_order_types). Omit for all types.', required: false),
            new ToolProperty(name: 'tags', type: PropertyType::STRING, description: 'Optional comma-separated order tag names to restrict to, e.g. "corporativo" (see list_order_types for the tags in use). An order matching any of them counts. Omit for all orders.', required: false),
            new ToolProperty(name: 'since', type: PropertyType::STRING, description: 'Lower-bound order date, ISO YYYY-MM-DD. Omit for all-time.', required: false),
            new ToolProperty(name: 'until', type: PropertyType::STRING, description: 'Upper-bound order date, ISO YYYY-MM-DD. Omit for open-ended.', required: false),
            new ToolProperty(name: 'paid_only', type: PropertyType::BOOLEAN, description: 'Count only orders with payment_status=paid. Default false (every order in the range, including draft and cancelled).', required: false),
            new ToolProperty(name: 'date_anchor', type: PropertyType::STRING, description: '"created" (default) buckets and filters by order creation date; "paid" by the date the order was paid, counting only paid orders.', required: false, enum: ['created', 'paid']),
            new ToolProperty(name: 'timezone', type: PropertyType::STRING, description: 'IANA timezone the days are cut in, e.g. "America/Santo_Domingo". Defaults to UTC.', required: false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        ?string $group_by = null,
        ?string $order_types = null,
        ?string $tags = null,
        ?string $since = null,
        ?string $until = null,
        ?bool $paid_only = null,
        ?string $date_anchor = null,
        ?string $timezone = null,
    ): array {
        return $this->oncePerTurn(
            [
                'group_by' => $group_by,
                'order_types' => $order_types,
                'tags' => $tags,
                'since' => $since,
                'until' => $until,
                'paid_only' => $paid_only,
                'date_anchor' => $date_anchor,
                'timezone' => $timezone,
            ],
            fn (): array => $this->runReport(
                fn (): array => new OrderReportService($this->app, $this->company)->trend(
                    orderTypeNames: $this->parseNames($order_types),
                    since: $since,
                    until: $until,
                    groupBy: $group_by,
                    paidOnly: $paid_only ?? false,
                    dateAnchor: $date_anchor,
                    timezone: $timezone,
                    tagNames: $this->parseNames($tags),
                )
            ),
        );
    }
}
