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

#[AgentTool(name: 'Order Breakdown', category: 'commerce')]
class OrderBreakdownTool implements KanvasToolInterface
{
    use HasKanvasContext;

    public function name(): string
    {
        return Str::slug(AgentTool::fromClass($this)?->name ?? class_basename($this), '_');
    }

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
        $orderTypes = array_filter((array) ($request['order_types'] ?? []));

        return json_encode(
            new OrderReportService($this->app, $this->company)->breakdown(
                $request->string('group_by') ? (string) $request->string('group_by') : null,
                $orderTypes,
                $request->string('since') ? (string) $request->string('since') : null,
                $request->string('until') ? (string) $request->string('until') : null,
            ),
            JSON_PRETTY_PRINT
        );
    }

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'group_by' => $schema->string()->description('Dimension to group by: "status" (default) or "type".'),
            'order_types' => $schema->array()->items($schema->string())->description('Optional order-type names to restrict to (see list_order_types). Omit for all types.'),
            'since' => $schema->string()->description('Lower-bound order date, ISO YYYY-MM-DD. Omit for all-time.'),
            'until' => $schema->string()->description('Upper-bound order date, ISO YYYY-MM-DD. Omit for open-ended.'),
        ];
    }
}
