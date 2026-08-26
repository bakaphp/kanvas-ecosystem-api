<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Neuron\Tools;

use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Connectors\Movipass\Neuron\Tools\Traits\ResolvesMovipassReportScope;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Souk\Orders\Actions\GetOrderPaymentStatsAction;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

#[AgentTool(name: 'Movipass Order Payment Stats', category: 'commerce')]
class OrderPaymentStatsTool extends Tool implements HasRunKey
{
    use HasKanvasContext;
    use ResolvesMovipassReportScope;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'movipass_order_payment_stats',
            description: 'Collected money for parking, Paso Rápido, impound or roadside orders: total amount, '
                . 'transaction count, average ticket, the card-vs-transfer split, a breakdown per service or '
                . 'variant sold, per provider, and the per-day/week/month series with its averages. Use for "how '
                . 'much did we collect", "what was the average ticket", "how much came in by card", "revenue by '
                . 'service", "recharges this month". This is the same calculation the payments dashboard shows, cut '
                . 'in the tenant timezone — prefer it over the generic order_payment_stats tool for anything '
                . 'Movipass. Look one tag or one order up with tag / order_number.',
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
                description: 'Restrict to one operation: movipass (parking), paso_rapido (tag recharges), impound_lot or roadside_assistance. Omit for all of them together.',
                required: false,
                enum: array_column(OrderTypeEnum::cases(), 'value'),
            ),
            new ToolProperty(name: 'since', type: PropertyType::STRING, description: 'Start of the window, ISO YYYY-MM-DD. Defaults to today.', required: false),
            new ToolProperty(name: 'until', type: PropertyType::STRING, description: 'End of the window, ISO YYYY-MM-DD. Defaults to today.', required: false),
            new ToolProperty(
                name: 'period_breakdown',
                type: PropertyType::STRING,
                description: 'Bucket size for the time series: DAY, WEEK, MONTH (default) or YEAR.',
                required: false,
                enum: ['DAY', 'WEEK', 'MONTH', 'YEAR'],
            ),
            new ToolProperty(name: 'paid_states', type: PropertyType::STRING, description: 'Comma-separated payment statuses that count as collected. Defaults to "paid". Pass "paid,pending,refunded,failed" to see every attempt.', required: false),
            new ToolProperty(name: 'timezone', type: PropertyType::STRING, description: 'IANA timezone the days are cut in, e.g. "America/Santo_Domingo". Defaults to the company timezone.', required: false),
            new ToolProperty(name: 'provider_company_id', type: PropertyType::INTEGER, description: 'Restrict to one provider company. Ignored for a provider agent, which is always scoped to its own company.', required: false),
            new ToolProperty(name: 'variant_id', type: PropertyType::INTEGER, description: 'Restrict to a single service or variant sold. Omit for all.', required: false),
            new ToolProperty(name: 'product_id', type: PropertyType::INTEGER, description: 'Restrict to one product — a single parking lot, for example. Omit for all.', required: false),
            new ToolProperty(name: 'tag', type: PropertyType::STRING, description: 'Paso Rápido tag number to look up. Matched with LIKE, so "%" wildcards are allowed. Only meaningful with order_type paso_rapido.', required: false),
            new ToolProperty(name: 'order_number', type: PropertyType::STRING, description: 'Exact order number to look up.', required: false),
            new ToolProperty(name: 'reference', type: PropertyType::STRING, description: 'Order reference to look up. Matches with LIKE.', required: false),
            new ToolProperty(name: 'user_email', type: PropertyType::STRING, description: 'Restrict to one customer email. Matches with LIKE.', required: false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        ?string $order_type = null,
        ?string $since = null,
        ?string $until = null,
        ?string $period_breakdown = null,
        ?string $paid_states = null,
        ?string $timezone = null,
        ?int $provider_company_id = null,
        ?int $variant_id = null,
        ?int $product_id = null,
        ?string $tag = null,
        ?string $order_number = null,
        ?string $reference = null,
        ?string $user_email = null,
    ): array {
        $type = $order_type !== null ? OrderTypeEnum::tryFrom(strtolower(trim($order_type))) : null;

        if ($order_type !== null && $type === null) {
            return [
                'status' => 'error',
                'message' => 'order_type must be one of: ' . implode(', ', array_column(OrderTypeEnum::cases(), 'value')) . '.',
            ];
        }

        $providerScope = $this->providerCompanyScope($provider_company_id);

        if ($providerScope === null) {
            return $this->providerScopeUnavailable();
        }

        $tag = $tag !== null ? trim($tag) : null;

        return new GetOrderPaymentStatsAction(
            app: $this->app,
            paidStates: $this->parseListParam($paid_states) ?? ['paid'],
            variantId: $variant_id,
            orderTypeNames: $type !== null ? [$type->value] : [],
            productId: $product_id,
            providerCompanyIds: $providerScope,
            userEmail: $user_email,
            reference: $reference,
            orderNumber: $order_number,
            metadataFilter: $tag !== null && $tag !== ''
                ? ['path' => 'data.paso_rapido_tag', 'value' => $tag, 'operator' => 'LIKE']
                : null,
        )->execute(
            startDate: $since,
            endDate: $until,
            timezone: $this->reportTimezone($timezone),
            periodBreakdown: strtoupper($period_breakdown ?? 'MONTH'),
        );
    }
}
