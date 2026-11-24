<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Tools\Souk;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Laravel\Concerns\HasKanvasContext;
use Kanvas\Intelligence\Agents\Laravel\Concerns\RunsOrderReports;
use Kanvas\Intelligence\Agents\Laravel\Contracts\KanvasToolInterface;
use Kanvas\Souk\Orders\Services\OrderReportService;
use Laravel\Ai\Tools\Request;
use Override;
use Stringable;

#[AgentTool(name: 'Active Users', category: 'commerce')]
class ActiveUsersTool implements KanvasToolInterface
{
    use HasKanvasContext;
    use RunsOrderReports;

    #[Override]
    public function description(): Stringable|string
    {
        return 'Active users, total and per vertical (order type): distinct users who made at least one PAID '
            . 'payment in the date range, with their paid orders, orders per user and average orders per month. '
            . 'Use for "how many active users do we have", "unique payers per vertical". The user counted is the '
            . 'one who paid, not the one who created the order. The range is anchored on the payment date, cut in '
            . 'the given timezone. total_active_users is deduplicated: never sum the per-vertical rows. Filter with '
            . 'order_types (names from list_order_types). Set group_by to "month" for a month-by-month series.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        return $this->report(fn (): array => new OrderReportService($this->app, $this->company)->activeUsers(
            orderTypeNames: $this->names($request, 'order_types'),
            since: $request->string('since') ? (string) $request->string('since') : null,
            until: $request->string('until') ? (string) $request->string('until') : null,
            timezone: $request->string('timezone')->toString() ?: 'America/Santo_Domingo',
            groupBy: $request->string('group_by') ? (string) $request->string('group_by') : null,
        ));
    }

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'order_types' => $schema->array()->items($schema->string())->description('Optional order-type names to restrict to (see list_order_types). Omit for all types.'),
            'since' => $schema->string()->description('Lower-bound payment date, ISO YYYY-MM-DD. Omit for all-time.'),
            'until' => $schema->string()->description('Upper-bound payment date, ISO YYYY-MM-DD. Omit for open-ended.'),
            'timezone' => $schema->string()->description('IANA timezone the days are cut in. Defaults to America/Santo_Domingo.'),
            'group_by' => $schema->string()->enum(['month'])->description('Set to "month" to also return a month-by-month series. Omit for totals only.'),
        ];
    }
}
