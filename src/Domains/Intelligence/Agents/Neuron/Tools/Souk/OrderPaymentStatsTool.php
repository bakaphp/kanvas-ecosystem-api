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

#[AgentTool(name: 'Order Payment Stats', category: 'commerce')]
class OrderPaymentStatsTool extends Tool
{
    use GuardsRepeatCalls;
    use HasKanvasContext;
    use ParsesOrderTypesFilter;
    use TrackByInputs;

    protected string $name = 'order_payment_stats';

    protected ?string $description = 'Collected money for the orders of this company: paid-order count, total paid amount, '
        . 'average order value, card-vs-other mix, a per-service breakdown and a per-period series. Each order '
        . 'counts on the date it was paid (first transition into "paid", else its first paid payment), not the '
        . 'date it was created, so this matches the payments dashboard. Use for "how much did we collect", '
        . '"recharge revenue this month", "card vs cash/transfer split". Amounts are net of discounts. For '
        . 'every order in a range whether paid or not, use order_trend or order_breakdown instead.';

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
            new ToolProperty(name: 'since', type: PropertyType::STRING, description: 'First payment date to include, ISO YYYY-MM-DD. Omit to start at the first order on record.', required: false),
            new ToolProperty(name: 'until', type: PropertyType::STRING, description: 'Last payment date to include, ISO YYYY-MM-DD. Omit for today.', required: false),
            new ToolProperty(name: 'timezone', type: PropertyType::STRING, description: 'IANA timezone the days are cut in, e.g. "America/Santo_Domingo". Defaults to UTC.', required: false),
            new ToolProperty(name: 'period_breakdown', type: PropertyType::STRING, description: 'Bucket size for the by_period series: DAY, WEEK, MONTH (default) or YEAR.', required: false, enum: ['DAY', 'WEEK', 'MONTH', 'YEAR']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        ?string $order_types = null,
        ?string $since = null,
        ?string $until = null,
        ?string $timezone = null,
        ?string $period_breakdown = null,
    ): array {
        return $this->oncePerTurn(
            [
                'order_types' => $order_types,
                'since' => $since,
                'until' => $until,
                'timezone' => $timezone,
                'period_breakdown' => $period_breakdown,
            ],
            fn (): array => new OrderReportService($this->app, $this->company)->paymentStats(
                orderTypeNames: $this->parseOrderTypes($order_types),
                since: $since,
                until: $until,
                timezone: $timezone,
                periodBreakdown: $period_breakdown,
            ),
        );
    }
}
