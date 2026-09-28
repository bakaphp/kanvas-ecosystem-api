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
 * Laravel-AI counterpart of the Neuron list_product_types tool.
 */
#[AgentTool(name: 'List Product Types', category: 'inventory')]
class ListProductTypesTool implements KanvasToolInterface
{
    use HandlesToolRequest;
    use HasKanvasContext;
    use ListsCatalogReferenceData;

    public function name(): string
    {
        return 'list_product_types';
    }

    #[Override]
    public function description(): Stringable|string
    {
        return 'List the product types available to this company, including the app-wide ones. A product type '
            . 'groups products that share a set of attributes; use this to get a product_type_id for '
            . 'create_product or update_product.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        return (string) json_encode(
            $this->listCatalogProductTypes(
                $this->nullableString($request, 'keyword'),
                $this->nullableInt($request, 'limit'),
            ),
            JSON_PRETTY_PRINT
        );
    }

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'keyword' => $schema
                ->string()
                ->description('Optional text to filter product types by name.'),
            'limit' => $schema
                ->integer()
                ->description('How many to return. Defaults to 25, maximum 100.'),
        ];
    }
}
