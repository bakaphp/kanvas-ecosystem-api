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

#[AgentTool(name: 'Order Breakdown', category: 'commerce')]
class OrderBreakdownTool implements KanvasToolInterface
{
    use HasKanvasContext;
    use RunsOrderReports;

    #[Override]
    public function description(): Stringable|string
    {
        return 'Order counts and gross revenue grouped by status or by order type over an optional date range, '
            . 'with an optional order-type filter. Use for "how many orders are pending vs paid vs cancelled", '
            . '"order volume by type this month", pipeline/funnel health. Includes every status (draft and '
            . 'cancelled too). For booked-revenue totals use sales_revenue instead.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        return $this->report(fn (): array => new OrderReportService($this->app, $this->company)->breakdown(
            $request->string('group_by') ? (string) $request->string('group_by') : null,
            $this->names($request, 'order_types'),
            $request->string('since') ? (string) $request->string('since') : null,
            $request->string('until') ? (string) $request->string('until') : null,
            $this->names($request, 'tags'),
        ));
    }

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'group_by' => $schema->string()->description('Dimension to group by: "status" (default) or "type".'),
            'order_types' => $schema->array()->items($schema->string())->description('Optional order-type names to restrict to (see list_order_types). Omit for all types.'),
            'tags' => $schema->array()->items($schema->string())->description('Optional order tag names to restrict to, e.g. "corporativo" (see list_order_types for the tags in use). An order matching any of them counts. Omit for all orders.'),
            'since' => $schema->string()->description('Lower-bound order date, ISO YYYY-MM-DD. Omit for all-time.'),
            'until' => $schema->string()->description('Upper-bound order date, ISO YYYY-MM-DD. Omit for open-ended.'),
        ];
    }
}
