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

#[AgentTool(name: 'Order Payment Stats', category: 'commerce')]
class OrderPaymentStatsTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'order_payment_stats',
            description: 'Paid-transaction money stats: total paid amount, paid-order count, average order value, '
                . 'and the card-vs-other payment-method mix. Optional date range and order-type filter. Use for '
                . '"how much did we collect", "recharge revenue this month", "card vs cash/transfer split". Amounts '
                . 'are net of discounts. By default only orders with payment_status=paid are counted.',
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
            new ToolProperty(name: 'paid_only', type: PropertyType::BOOLEAN, description: 'Count only orders with payment_status=paid. Default true. Set false to include every order in the range.', required: false),
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
        ?bool $paid_only = null,
    ): array {
        return new OrderReportService($this->app, $this->company)
            ->paymentStats($order_types, $since, $until, $paid_only ?? true);
    }
}
