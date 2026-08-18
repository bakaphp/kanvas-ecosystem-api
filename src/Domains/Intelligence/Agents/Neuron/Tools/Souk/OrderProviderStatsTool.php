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

#[AgentTool(name: 'Order Provider Stats', category: 'commerce')]
class OrderProviderStatsTool extends Tool
{
    use HasKanvasContext;
    use ParsesOrderTypesFilter;

    public function __construct()
    {
        parent::__construct(
            name: 'order_provider_stats',
            description: 'Marketplace split per provider company: orders, net revenue, commission we earned and '
                . 'payout owed to that provider, ranked by revenue. Use for "what do we owe each provider", "which '
                . 'provider brings the most volume", "commission by provider". Also returns how many orders in the '
                . 'range have no provider attached at all. Providers come from the order-provider link, not from '
                . 'the customer email. For a single company-wide total use order_commission_stats.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(name: 'limit', type: PropertyType::INTEGER, description: 'Max providers to return. Default 10, max 50.', required: false),
            new ToolProperty(name: 'order_types', type: PropertyType::STRING, description: 'Optional comma-separated order-type names to restrict to (see list_order_types). Omit for all types.', required: false),
            new ToolProperty(name: 'since', type: PropertyType::STRING, description: 'Lower-bound order date, ISO YYYY-MM-DD. Omit for all-time.', required: false),
            new ToolProperty(name: 'until', type: PropertyType::STRING, description: 'Upper-bound order date, ISO YYYY-MM-DD. Omit for open-ended.', required: false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        ?int $limit = null,
        ?string $order_types = null,
        ?string $since = null,
        ?string $until = null,
    ): array {
        return new OrderReportService($this->app, $this->company)->providerStats(
            $this->parseOrderTypes($order_types),
            $since,
            $until,
            max(1, min(50, $limit ?? 10)),
        );
    }
}
