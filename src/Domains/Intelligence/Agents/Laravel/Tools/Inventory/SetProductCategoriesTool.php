<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Tools\Inventory;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Laravel\Contracts\KanvasToolInterface;
use Kanvas\Intelligence\Agents\Laravel\Traits\GuardsAdminForTool;
use Kanvas\Intelligence\Agents\Laravel\Traits\HandlesToolRequest;
use Kanvas\Intelligence\Agents\Laravel\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Traits\ManagesCatalogProducts;
use Laravel\Ai\Tools\Request;
use Override;
use Stringable;

/**
 * Laravel-AI counterpart of the Neuron set_product_categories tool.
 */
#[AgentTool(name: 'Set Product Categories', category: 'inventory')]
class SetProductCategoriesTool implements KanvasToolInterface
{
    use GuardsAdminForTool;
    use HandlesToolRequest;
    use HasKanvasContext;
    use ManagesCatalogProducts;

    public function name(): string
    {
        return 'set_product_categories';
    }

    #[Override]
    public function description(): Stringable|string
    {
        return 'File a product under one or more categories. By default the categories you pass are added to '
            . 'whatever the product already has; pass replace=true only when you mean to discard its current '
            . 'categories. Use category_search to find the ids first — never guess them. Only an administrator can '
            . 'do this.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $denied = $this->adminDenialFor('change product categories');

        if ($denied !== null) {
            return $denied;
        }

        return (string) json_encode(
            $this->setCatalogProductCategories(
                productId: $request->integer('product_id'),
                categoryIds: array_map('intval', $request->array('category_ids')),
                replace: $request->boolean('replace'),
            ),
            JSON_PRETTY_PRINT
        );
    }

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'product_id' => $schema
                ->integer()
                ->description('The ID of the product to file (from list_available_products or inventory_search).')
                ->required(),
            'category_ids' => $schema
                ->array()
                ->items($schema->integer()->description('A category id.'))
                ->description('The category ids to file the product under, from category_search.')
                ->required(),
            'replace' => $schema
                ->boolean()
                ->description(
                    'true to discard the product\'s current categories and keep only the ones you pass. Defaults '
                    . 'to false, which adds to them.'
                )
                ->default(false),
        ];
    }
}
