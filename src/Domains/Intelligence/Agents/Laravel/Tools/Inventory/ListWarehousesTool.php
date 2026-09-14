<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Tools\Inventory;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Laravel\Contracts\KanvasToolInterface;
use Kanvas\Intelligence\Agents\Laravel\Traits\HandlesToolRequest;
use Kanvas\Intelligence\Agents\Laravel\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Traits\ListsCatalogReferenceData;
use Laravel\Ai\Tools\Request;
use Override;
use Stringable;

/**
 * Laravel-AI counterpart of the Neuron list_warehouses tool.
 */
#[AgentTool(name: 'List Warehouses', category: 'inventory')]
class ListWarehousesTool implements KanvasToolInterface
{
    use HandlesToolRequest;
    use HasKanvasContext;
    use ListsCatalogReferenceData;

    public function name(): string
    {
        return 'list_warehouses';
    }

    #[Override]
    public function description(): Stringable|string
    {
        return 'List the company\'s warehouses, default first. Stock, cost and warehouse price are held per '
            . 'warehouse, so use this to get a warehouse_id before calling set_variant_stock for a specific '
            . 'location. Omitting warehouse_id on those tools uses the default warehouse.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        return (string) json_encode(
            $this->listCatalogWarehouses($this->nullableInt($request, 'limit')),
            JSON_PRETTY_PRINT
        );
    }

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema
                ->integer()
                ->description('How many warehouses to return. Defaults to 25, maximum 100.'),
        ];
    }
}
