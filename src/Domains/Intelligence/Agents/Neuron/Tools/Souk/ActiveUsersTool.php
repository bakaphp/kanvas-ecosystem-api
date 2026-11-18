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

#[AgentTool(name: 'Active Users', category: 'commerce')]
class ActiveUsersTool extends Tool
{
    use GuardsRepeatCalls;
    use HasKanvasContext;
    use ReportsToolOutcome;
    use RunsOrderReports;
    use TrackByInputs;

    protected string $name = 'active_users';

    protected ?string $description = 'Active users, total and per vertical (order type): distinct users who made at least one PAID '
        . 'payment in the date range, with their paid orders, orders per user and average orders per month. '
        . 'Use for "how many active users do we have", "unique payers per vertical", "usuarios activos por '
        . 'vertical". The user counted is the one who paid, not the one who created the order. The range is '
        . 'anchored on the payment date, cut in the given timezone. total_active_users is deduplicated: a user '
        . 'active in two verticals counts once in the total but once in each vertical row, so never sum the '
        . 'rows. Filter with order_types (names from list_order_types); an unknown name comes back as '
        . 'invalid_args listing the valid ones. Set group_by to "month" for a month-by-month series. For '
        . 'money collected use order_payment_stats; for order volume use order_trend.';

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
            new ToolProperty(name: 'since', type: PropertyType::STRING, description: 'Lower-bound payment date, ISO YYYY-MM-DD. Omit for all-time.', required: false),
            new ToolProperty(name: 'until', type: PropertyType::STRING, description: 'Upper-bound payment date, ISO YYYY-MM-DD. Omit for open-ended.', required: false),
            new ToolProperty(name: 'timezone', type: PropertyType::STRING, description: 'IANA timezone the days are cut in. Defaults to America/Santo_Domingo.', required: false),
            new ToolProperty(name: 'group_by', type: PropertyType::STRING, description: 'Set to "month" to also return a month-by-month series. Omit for totals only.', required: false, enum: ['month']),
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
        ?string $group_by = null,
    ): array {
        return $this->oncePerTurn(
            [
                'order_types' => $order_types,
                'since' => $since,
                'until' => $until,
                'timezone' => $timezone,
                'group_by' => $group_by,
            ],
            fn (): array => $this->runReport(
                fn (): array => new OrderReportService($this->app, $this->company)->activeUsers(
                    orderTypeNames: $this->parseNames($order_types),
                    since: $since,
                    until: $until,
                    timezone: $timezone ?? 'America/Santo_Domingo',
                    groupBy: $group_by,
                )
            ),
        );
    }
}
