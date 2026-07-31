<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Souk;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Souk\Orders\Services\OrderReportService;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

#[AgentTool(name: 'Order Commission Stats', category: 'commerce')]
class OrderCommissionStatsTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'order_commission_stats',
            description: 'Revenue split for commissioned orders: gross revenue, platform commission earned, and '
                . 'provider payout owed, plus the order count. Optional date range and order-type filter. Use for '
                . '"how much commission did we earn", "what do we owe provider X", marketplace take-rate questions. '
                . 'Counts only orders with a commission configured, anchored on order creation date.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(name: 'order_types', type: PropertyType::ARRAY, description: 'Optional order-type names to restrict to (see list_order_types). Omit for all types.', required: false),
            new ToolProperty(name: 'since', type: PropertyType::STRING, description: 'Lower-bound order date, ISO YYYY-MM-DD. Omit for all-time.', required: false),
            new ToolProperty(name: 'until', type: PropertyType::STRING, description: 'Upper-bound order date, ISO YYYY-MM-DD. Omit for open-ended.', required: false),
        ];
    }

    /**
     * @param string[]|null $order_types
     *
     * @return array<string, mixed>
     */
    public function __invoke(
        ?array $order_types = null,
        ?string $since = null,
        ?string $until = null,
    ): array {
        return new OrderReportService($this->app, $this->company)
            ->commissionStats($order_types, $since, $until);
    }
}
