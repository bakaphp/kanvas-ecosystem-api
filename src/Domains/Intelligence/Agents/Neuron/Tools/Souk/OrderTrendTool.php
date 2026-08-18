<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Souk;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ParsesOrderTypesFilter;
use Kanvas\Souk\Orders\Services\OrderReportService;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

#[AgentTool(name: 'Order Trend', category: 'commerce')]
class OrderTrendTool extends Tool
{
    use HasKanvasContext;
    use ParsesOrderTypesFilter;

    public function __construct()
    {
        parent::__construct(
            name: 'order_trend',
            description: 'Order count and revenue over time, bucketed by day, week or month, with the per-period '
                . 'averages plus the busiest and slowest period in the range. Use for "how are orders trending", '
                . '"revenue month by month", "which week was our best", "is volume going up or down". Returns one '
                . 'row per period that actually has orders — periods with none are omitted, not zero-filled. For a '
                . 'single total instead of a series use sales_revenue or order_payment_stats.',
        );
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
            new ToolProperty(name: 'since', type: PropertyType::STRING, description: 'Lower-bound order date, ISO YYYY-MM-DD. Omit for all-time.', required: false),
            new ToolProperty(name: 'until', type: PropertyType::STRING, description: 'Upper-bound order date, ISO YYYY-MM-DD. Omit for open-ended.', required: false),
            new ToolProperty(name: 'paid_only', type: PropertyType::BOOLEAN, description: 'Count only orders with payment_status=paid. Default false (every order in the range, including draft and cancelled).', required: false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        ?string $group_by = null,
        ?string $order_types = null,
        ?string $since = null,
        ?string $until = null,
        ?bool $paid_only = null,
    ): array {
        return new OrderReportService($this->app, $this->company)->trend(
            $this->parseOrderTypes($order_types),
            $since,
            $until,
            $group_by,
            $paid_only ?? false,
        );
    }
}
