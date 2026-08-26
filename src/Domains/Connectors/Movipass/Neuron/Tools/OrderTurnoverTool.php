<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Neuron\Tools;

use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Connectors\Movipass\Neuron\Tools\Traits\ResolvesMovipassReportScope;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Souk\Orders\Actions\GetOrderStatsAction;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

#[AgentTool(name: 'Movipass Order Turnover', category: 'commerce')]
class OrderTurnoverTool extends Tool implements HasRunKey
{
    use HasKanvasContext;
    use ResolvesMovipassReportScope;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'movipass_order_turnover',
            description: 'Entries, exits and how many orders are still open right now, measured off status '
                . 'transitions rather than order creation. Use for "how many cars came in today", "how many left", '
                . '"how many are still parked", "how many vehicles are in the lot right now", "what was our busiest '
                . 'day", and average dwell time. This is the report the operations dashboard draws: an order counts '
                . 'as an entry when it reaches the opening status and as an exit when it reaches the closing one, so '
                . 'the same order can enter on Monday and exit on Thursday. Opening and closing statuses default to '
                . 'the right ones for the chosen order type — override them only when asked for a specific status.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'order_type',
                type: PropertyType::STRING,
                description: 'Which operation to report on: movipass (parking), impound_lot (tow yard) or roadside_assistance. Paso Rápido has no dwell time — use movipass_order_payment_stats for it.',
                required: true,
                enum: OrderTypeEnum::turnoverCapableValues(),
            ),
            new ToolProperty(name: 'since', type: PropertyType::STRING, description: 'Start of the window, ISO YYYY-MM-DD. Defaults to today.', required: false),
            new ToolProperty(name: 'until', type: PropertyType::STRING, description: 'End of the window, ISO YYYY-MM-DD. Defaults to today.', required: false),
            new ToolProperty(
                name: 'group_by',
                type: PropertyType::STRING,
                description: 'Bucket size for the series: DAY (default), WEEK or MONTH.',
                required: false,
                enum: ['DAY', 'WEEK', 'MONTH'],
            ),
            new ToolProperty(name: 'base_date', type: PropertyType::STRING, description: 'Date the "still open right now" count is taken from, ISO YYYY-MM-DD. Omit for a live count.', required: false),
            new ToolProperty(name: 'timezone', type: PropertyType::STRING, description: 'IANA timezone the days are cut in, e.g. "America/Santo_Domingo". Defaults to the company timezone.', required: false),
            new ToolProperty(name: 'provider_company_id', type: PropertyType::INTEGER, description: 'Restrict to one provider company. Ignored for a provider agent, which is always scoped to its own company.', required: false),
            new ToolProperty(name: 'initial_states', type: PropertyType::STRING, description: 'Comma-separated status slugs that count as an entry. Omit to use the defaults for the order type.', required: false),
            new ToolProperty(name: 'final_states', type: PropertyType::STRING, description: 'Comma-separated status slugs that count as an exit. Omit to use the defaults for the order type.', required: false),
            new ToolProperty(name: 'current_states', type: PropertyType::STRING, description: 'Comma-separated status slugs that count as still open. Omit to use the defaults for the order type.', required: false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        string $order_type,
        ?string $since = null,
        ?string $until = null,
        ?string $group_by = null,
        ?string $base_date = null,
        ?string $timezone = null,
        ?int $provider_company_id = null,
        ?string $initial_states = null,
        ?string $final_states = null,
        ?string $current_states = null,
    ): array {
        $type = OrderTypeEnum::tryFrom(strtolower(trim($order_type)));
        $states = $type?->turnoverStates();

        if ($type === null || $states === null) {
            return [
                'status' => 'error',
                'message' => 'order_type must be one of: ' . implode(', ', OrderTypeEnum::turnoverCapableValues()) . '.',
            ];
        }

        $providerScope = $this->providerCompanyScope($provider_company_id);

        if ($providerScope === null) {
            return $this->providerScopeUnavailable();
        }

        return new GetOrderStatsAction(
            app: $this->app,
            initialStates: $this->parseListParam($initial_states) ?? $states['initial'],
            finalStates: $this->parseListParam($final_states) ?? $states['final'],
            currentCountStates: $this->parseListParam($current_states) ?? $states['current'],
            orderTypeNames: [$type->value],
            providerCompanyIds: $providerScope,
        )->execute(
            startDate: $since,
            endDate: $until,
            baseDate: $base_date,
            timezone: $this->reportTimezone($timezone),
            groupBy: strtolower($group_by ?? 'day'),
        );
    }
}
