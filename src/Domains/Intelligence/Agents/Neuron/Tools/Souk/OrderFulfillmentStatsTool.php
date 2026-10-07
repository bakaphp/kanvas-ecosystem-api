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

#[AgentTool(name: 'Order Fulfillment Stats', category: 'commerce')]
class OrderFulfillmentStatsTool extends Tool
{
    use GuardsRepeatCalls;
    use HasKanvasContext;
    use ParsesOrderTypesFilter;
    use TrackByInputs;

    protected string $name = 'order_fulfillment_stats';

    protected ?string $description = 'Operational pipeline health: order counts and amounts grouped by payment status and by '
        . 'fulfillment status, plus two backlogs — orders already paid but not yet fulfilled, and open '
        . 'orders that have not been collected. Use for "what do we owe customers", "how many orders are '
        . 'waiting to ship", "how much money is uncollected", "are we behind on fulfillment". Backlogs '
        . 'exclude draft, cancelled and failed orders; the two status breakdowns include every order.';

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
            new ToolProperty(name: 'order_types', type: PropertyType::STRING, description: 'Optional comma-separated order-type names to restrict to (see list_order_types). Omit for all types.', required: false),
            new ToolProperty(name: 'since', type: PropertyType::STRING, description: 'Lower-bound order date, ISO YYYY-MM-DD. Omit for all-time.', required: false),
            new ToolProperty(name: 'until', type: PropertyType::STRING, description: 'Upper-bound order date, ISO YYYY-MM-DD. Omit for open-ended.', required: false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        ?string $order_types = null,
        ?string $since = null,
        ?string $until = null,
    ): array {
        return $this->oncePerTurn(
            [
                'order_types' => $order_types,
                'since' => $since,
                'until' => $until,
            ],
            fn (): array => new OrderReportService($this->app, $this->company)
                ->fulfillmentStats($this->parseOrderTypes($order_types), $since, $until),
        );
    }
}
