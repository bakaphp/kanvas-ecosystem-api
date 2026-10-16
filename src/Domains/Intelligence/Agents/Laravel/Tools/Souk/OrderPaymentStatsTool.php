<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Tools\Souk;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Laravel\Concerns\HasKanvasContext;
use Kanvas\Intelligence\Agents\Laravel\Contracts\KanvasToolInterface;
use Kanvas\Souk\Orders\Services\OrderReportService;
use Laravel\Ai\Tools\Request;
use Override;
use Stringable;

#[AgentTool(name: 'Order Payment Stats', category: 'commerce')]
class OrderPaymentStatsTool implements KanvasToolInterface
{
    use HasKanvasContext;

    #[Override]
    public function description(): Stringable|string
    {
        return 'Collected money for the orders of this company: paid-order count, total paid amount, average order '
            . 'value, card-vs-other mix, a per-service breakdown and a per-period series. Each order counts on the '
            . 'date it was paid (first transition into "paid", else its first paid payment), not the date it was '
            . 'created, so this matches the payments dashboard. Use for "how much did we collect", "recharge revenue '
            . 'this month", "card vs cash/transfer split". Amounts are net of discounts. For every order in a range '
            . 'whether paid or not, use order_trend or order_breakdown instead.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $orderTypes = array_filter((array) ($request['order_types'] ?? []));

        return json_encode(
            new OrderReportService($this->app, $this->company)->paymentStats(
                orderTypeNames: $orderTypes,
                since: $request->string('since') ? (string) $request->string('since') : null,
                until: $request->string('until') ? (string) $request->string('until') : null,
                timezone: $request->string('timezone') ? (string) $request->string('timezone') : null,
                periodBreakdown: $request->string('period_breakdown') ? (string) $request->string('period_breakdown') : null,
            ),
            JSON_PRETTY_PRINT
        );
    }

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'order_types' => $schema->array()->items($schema->string())->description('Optional order-type names to restrict to (see list_order_types). Omit for all types.'),
            'since' => $schema->string()->description('First payment date to include, ISO YYYY-MM-DD. Omit to start at the first order on record.'),
            'until' => $schema->string()->description('Last payment date to include, ISO YYYY-MM-DD. Omit for today.'),
            'timezone' => $schema->string()->description('IANA timezone the days are cut in, e.g. "America/Santo_Domingo". Defaults to UTC.'),
            'period_breakdown' => $schema->string()->enum(['DAY', 'WEEK', 'MONTH', 'YEAR'])->description('Bucket size for the by_period series. Defaults to MONTH.'),
        ];
    }
}
