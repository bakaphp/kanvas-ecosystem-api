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

#[AgentTool(name: 'Order Provider Stats', category: 'commerce')]
class OrderProviderStatsTool implements KanvasToolInterface
{
    use HasKanvasContext;

    public function name(): string
    {
        return Str::slug(AgentTool::fromClass($this)?->name ?? class_basename($this), '_');
    }

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
        $orderTypes = array_filter((array) ($request['order_types'] ?? []));

        return json_encode(
            new OrderReportService($this->app, $this->company)->providerStats(
                $orderTypes,
                $request->string('since') ? (string) $request->string('since') : null,
                $request->string('until') ? (string) $request->string('until') : null,
                max(1, min(50, $request->integer('limit') ?: 10)),
            ),
            JSON_PRETTY_PRINT
        );
    }

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()->description('Max providers to return. Default 10, max 50.'),
            'order_types' => $schema->array()->items($schema->string())->description('Optional order-type names to restrict to (see list_order_types). Omit for all types.'),
            'since' => $schema->string()->description('Lower-bound order date, ISO YYYY-MM-DD. Omit for all-time.'),
            'until' => $schema->string()->description('Upper-bound order date, ISO YYYY-MM-DD. Omit for open-ended.'),
        ];
    }
}
