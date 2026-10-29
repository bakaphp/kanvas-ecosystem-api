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

#[AgentTool(name: 'Order Provider Stats', category: 'commerce')]
class OrderProviderStatsTool implements KanvasToolInterface
{
    use HasKanvasContext;
    use RunsOrderReports;

    #[Override]
    public function description(): Stringable|string
    {
        return 'Marketplace split per provider company: orders, net revenue, commission we earned and payout owed '
            . 'to that provider, ranked by revenue. Use for "what do we owe each provider", "which provider brings '
            . 'the most volume", "commission by provider". Also returns how many orders in the range have no '
            . 'provider attached at all. Providers come from the order-provider link, not from the customer email. '
            . 'For a single company-wide total use order_commission_stats.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        return $this->report(fn (): array => new OrderReportService($this->app, $this->company)->providerStats(
            $this->names($request, 'order_types'),
            $request->string('since') ? (string) $request->string('since') : null,
            $request->string('until') ? (string) $request->string('until') : null,
            max(1, min(50, $request->integer('limit') ?: 10)),
            $this->names($request, 'tags'),
        ));
    }

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()->description('Max providers to return. Default 10, max 50.'),
            'order_types' => $schema->array()->items($schema->string())->description('Optional order-type names to restrict to (see list_order_types). Omit for all types.'),
            'tags' => $schema->array()->items($schema->string())->description('Optional order tag names to restrict to, e.g. "corporativo" (see list_order_types for the tags in use). An order matching any of them counts. Omit for all orders.'),
            'since' => $schema->string()->description('Lower-bound order date, ISO YYYY-MM-DD. Omit for all-time.'),
            'until' => $schema->string()->description('Upper-bound order date, ISO YYYY-MM-DD. Omit for open-ended.'),
        ];
    }
}
