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

#[AgentTool(name: 'Order Trend', category: 'commerce')]
class OrderTrendTool implements KanvasToolInterface
{
    use HasKanvasContext;

    #[Override]
    public function description(): Stringable|string
    {
        return 'Order count and revenue over time, bucketed by day, week or month, with the per-period averages '
            . 'plus the busiest and slowest period in the range. Use for "how are orders trending", "revenue month '
            . 'by month", "which week was our best", "is volume going up or down". Returns one row per period that '
            . 'actually has orders — periods with none are omitted, not zero-filled. For a single total instead of '
            . 'a series use sales_revenue or order_payment_stats. Set date_anchor to "paid" to bucket by the date each '
            . 'order was paid instead of the date it was created, and pass timezone so days are cut in local time '
            . 'instead of UTC.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $orderTypes = array_filter((array) ($request['order_types'] ?? []));

        return json_encode(
            new OrderReportService($this->app, $this->company)->trend(
                orderTypeNames: $orderTypes,
                since: $request->string('since') ? (string) $request->string('since') : null,
                until: $request->string('until') ? (string) $request->string('until') : null,
                groupBy: $request->string('group_by') ? (string) $request->string('group_by') : null,
                paidOnly: $request->boolean('paid_only', false),
                dateAnchor: $request->string('date_anchor') ? (string) $request->string('date_anchor') : null,
                timezone: $request->string('timezone') ? (string) $request->string('timezone') : null,
            ),
            JSON_PRETTY_PRINT
        );
    }

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'group_by' => $schema->string()->description('Bucket size: "day", "week" (weeks start Monday) or "month" (default).'),
            'order_types' => $schema->array()->items($schema->string())->description('Optional order-type names to restrict to (see list_order_types). Omit for all types.'),
            'since' => $schema->string()->description('Lower-bound order date, ISO YYYY-MM-DD. Omit for all-time.'),
            'until' => $schema->string()->description('Upper-bound order date, ISO YYYY-MM-DD. Omit for open-ended.'),
            'paid_only' => $schema->boolean()->description('Count only orders with payment_status=paid. Default false (every order in the range, including draft and cancelled).'),
            'date_anchor' => $schema->string()->enum(['created', 'paid'])->description('"created" (default) buckets and filters by order creation date; "paid" by the date the order was paid, counting only paid orders.'),
            'timezone' => $schema->string()->description('IANA timezone the days are cut in, e.g. "America/Santo_Domingo". Defaults to UTC.'),
        ];
    }
}
