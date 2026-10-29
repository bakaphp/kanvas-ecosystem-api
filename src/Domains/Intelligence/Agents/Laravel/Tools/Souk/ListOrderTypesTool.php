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

#[AgentTool(name: 'List Order Types', category: 'commerce')]
class ListOrderTypesTool implements KanvasToolInterface
{
    use HasKanvasContext;

    #[Override]
    public function description(): Stringable|string
    {
        return 'List the order-type names (and ids) configured for this company/app, plus the tag names '
            . 'currently carried by its orders. Call this to discover the valid values for the order_types and '
            . 'tags filters used by order_trend, order_breakdown, order_payment_stats, order_fulfillment_stats, '
            . 'order_provider_stats and order_commission_stats. A customer segment such as "corporativo" is a '
            . 'tag, not an order type. Returns empty lists when the tenant has no order types or no tagged orders.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        return json_encode(
            new OrderReportService($this->app, $this->company)->orderTypes(),
            JSON_PRETTY_PRINT
        );
    }

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
