<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Souk;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Souk\Orders\Services\OrderReportService;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

#[AgentTool(name: 'List Order Types', category: 'commerce')]
class ListOrderTypesTool extends Tool
{
    use HasKanvasContext;

    protected string $name = 'list_order_types';

    protected ?string $description = 'List the order-type names (and ids) configured for this company/app, plus the tag names '
        . 'currently carried by its orders. Call this to discover the valid values for the order_types and '
        . 'tags filters used by order_trend, order_breakdown, order_payment_stats, order_fulfillment_stats, '
        . 'order_provider_stats and order_commission_stats. A customer segment such as "corporativo" is a '
        . 'tag, not an order type. Returns empty lists when the tenant has no order types (all orders are '
        . 'then untyped) or no tagged orders.';

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(): array
    {
        return new OrderReportService($this->app, $this->company)->orderTypes();
    }
}
