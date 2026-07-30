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

    public function __construct()
    {
        parent::__construct(
            name: 'list_order_types',
            description: 'List the order-type names (and ids) configured for this company/app. Call this to '
                . 'discover the valid values for the order_types filter used by order_breakdown, '
                . 'order_payment_stats and order_commission_stats. Returns an empty list when the tenant has '
                . 'no order types (all orders are then untyped).',
        );
    }

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
