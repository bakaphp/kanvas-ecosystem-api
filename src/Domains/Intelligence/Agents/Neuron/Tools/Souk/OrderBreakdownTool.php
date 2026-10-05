<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Souk;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsRepeatCalls;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ParsesOrderTypesFilter;
use Kanvas\Souk\Orders\Services\OrderReportService;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

#[AgentTool(name: 'Order Breakdown', category: 'commerce')]
class OrderBreakdownTool extends Tool
{
    use GuardsRepeatCalls;
    use HasKanvasContext;
    use ParsesOrderTypesFilter;
    use TrackByInputs;

    protected string $name = 'order_breakdown';

    protected ?string $description = 'Order counts and gross revenue grouped by status or by order type over an optional '
        . 'date range, with an optional order-type filter. Use for "how many orders are pending vs paid vs '
        . 'cancelled", "order volume by type this month", pipeline/funnel health. Includes every status '
        . '(draft and cancelled too). For booked-revenue totals use sales_revenue instead.';

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
            new ToolProperty(name: 'group_by', type: PropertyType::STRING, description: 'Dimension to group by: "status" (default) or "type".', required: false),
            new ToolProperty(name: 'order_types', type: PropertyType::STRING, description: 'Optional comma-separated order-type names to restrict to (see list_order_types). Omit for all types.', required: false),
            new ToolProperty(name: 'since', type: PropertyType::STRING, description: 'Lower-bound order date, ISO YYYY-MM-DD. Omit for all-time.', required: false),
            new ToolProperty(name: 'until', type: PropertyType::STRING, description: 'Upper-bound order date, ISO YYYY-MM-DD. Omit for open-ended.', required: false),
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
    ): array {
        return $this->oncePerTurn(
            [
                'group_by' => $group_by,
                'order_types' => $order_types,
                'since' => $since,
                'until' => $until,
            ],
            fn (): array => new OrderReportService($this->app, $this->company)->breakdown(
                $group_by,
                $this->parseOrderTypes($order_types),
                $since,
                $until,
            ),
        );
    }
}
