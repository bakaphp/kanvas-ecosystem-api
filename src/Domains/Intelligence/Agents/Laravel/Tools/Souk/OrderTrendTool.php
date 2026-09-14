<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Tools\Souk;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Laravel\Contracts\KanvasToolInterface;
use Kanvas\Intelligence\Agents\Laravel\Traits\HasKanvasContext;
use Kanvas\Souk\Orders\Services\OrderReportService;
use Laravel\Ai\Tools\Request;
use Override;
use Stringable;

#[AgentTool(name: 'Order Trend', category: 'commerce')]
class OrderTrendTool implements KanvasToolInterface
{
    use HasKanvasContext;

    public function name(): string
    {
        return Str::slug(AgentTool::fromClass($this)?->name ?? class_basename($this), '_');
    }

    #[Override]
    public function description(): Stringable|string
    {
        return 'Order count and revenue over time, bucketed by day, week or month, with the per-period averages '
            . 'plus the busiest and slowest period in the range. Use for "how are orders trending", "revenue month '
            . 'by month", "which week was our best", "is volume going up or down". Returns one row per period that '
            . 'actually has orders — periods with none are omitted, not zero-filled. For a single total instead of '
            . 'a series use sales_revenue or order_payment_stats.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $orderTypes = array_filter((array) ($request['order_types'] ?? []));

        return json_encode(
            new OrderReportService($this->app, $this->company)->trend(
                $orderTypes,
                $request->string('since') ? (string) $request->string('since') : null,
                $request->string('until') ? (string) $request->string('until') : null,
                $request->string('group_by') ? (string) $request->string('group_by') : null,
                $request->boolean('paid_only', false),
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
        ];
    }
}
