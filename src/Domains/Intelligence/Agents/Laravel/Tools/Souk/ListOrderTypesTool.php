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

#[AgentTool(name: 'List Order Types', category: 'commerce')]
class ListOrderTypesTool implements KanvasToolInterface
{
    use HasKanvasContext;

    public function name(): string
    {
        return Str::slug(AgentTool::fromClass($this)?->name ?? class_basename($this), '_');
    }

    #[Override]
    public function description(): Stringable|string
    {
        return 'List the order-type names (and ids) configured for this company/app. Call this to discover '
            . 'the valid values for the order_types filter used by order_breakdown, order_payment_stats and '
            . 'order_commission_stats. Returns an empty list when the tenant has no order types.';
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
