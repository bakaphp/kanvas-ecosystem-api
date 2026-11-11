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

#[AgentTool(name: 'Order Fulfillment Stats', category: 'commerce')]
class OrderFulfillmentStatsTool implements KanvasToolInterface
{
    use HasKanvasContext;
    use RunsOrderReports;

    #[Override]
    public function description(): Stringable|string
    {
        return 'Operational pipeline health: order counts and amounts grouped by payment status and by fulfillment '
            . 'status, plus two backlogs — orders already paid but not yet fulfilled, and open orders that have not '
            . 'been collected. Use for "what do we owe customers", "how many orders are waiting to ship", "how much '
            . 'money is uncollected", "are we behind on fulfillment". Backlogs exclude draft, cancelled and failed '
            . 'orders; the two status breakdowns include every order.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        return $this->report(fn (): array => new OrderReportService($this->app, $this->company)->fulfillmentStats(
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
            'order_types' => $schema->array()->items($schema->string())->description('Optional order-type names to restrict to (see list_order_types). Omit for all types.'),
            'tags' => $schema->array()->items($schema->string())->description('Optional order tag names to restrict to, e.g. "corporativo" (see list_order_types for the tags in use). An order matching any of them counts. Omit for all orders.'),
            'since' => $schema->string()->description('Lower-bound order date, ISO YYYY-MM-DD. Omit for all-time.'),
            'until' => $schema->string()->description('Upper-bound order date, ISO YYYY-MM-DD. Omit for open-ended.'),
        ];
    }
}
