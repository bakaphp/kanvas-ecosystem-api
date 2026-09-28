<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Tools\Inventory;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Laravel\Contracts\KanvasToolInterface;
use Kanvas\Intelligence\Agents\Laravel\Traits\GuardsAdminForTool;
use Kanvas\Intelligence\Agents\Laravel\Traits\HandlesToolRequest;
use Kanvas\Intelligence\Agents\Laravel\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Traits\ManagesCatalogTaxonomy;
use Laravel\Ai\Tools\Request;
use Override;
use Stringable;

/**
 * Laravel-AI counterpart of the Neuron create_category tool.
 */
#[AgentTool(name: 'Create Category', category: 'inventory')]
class CreateCategoryTool implements KanvasToolInterface
{
    use GuardsAdminForTool;
    use HandlesToolRequest;
    use HasKanvasContext;
    use ManagesCatalogTaxonomy;

    public function name(): string
    {
        return 'create_category';
    }

    #[Override]
    public function description(): Stringable|string
    {
        return 'Create a product category. Search with category_search first — a category with the same name is '
            . 'reused rather than duplicated, and a near-duplicate ("Shoes" next to "Shoe") makes the catalog '
            . 'worse. Pass parent_id to nest it under an existing category. Once it exists, file products into it '
            . 'with set_product_categories. Only an administrator can do this.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $denied = $this->adminDenialFor('create categories');

        if ($denied !== null) {
            return $denied;
        }

        return (string) json_encode(
            $this->createCatalogCategory(
                name: (string) $request->string('name'),
                parentId: $this->nullableInt($request, 'parent_id'),
                description: $this->nullableString($request, 'description'),
                code: $this->nullableString($request, 'code'),
                isPublished: $this->nullableBool($request, 'is_published'),
            ),
            JSON_PRETTY_PRINT
        );
    }

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema
                ->string()
                ->description('The category name as a shopper would see it.')
                ->required(),
            'parent_id' => $schema
                ->integer()
                ->description(
                    'Category to nest this one under, from category_search. Omit for a top-level category.'
                ),
            'description' => $schema
                ->string()
                ->description('What belongs in this category.'),
            'code' => $schema
                ->string()
                ->description('Internal code for the category, when the company uses one.'),
            'is_published' => $schema
                ->boolean()
                ->description('Whether the category is visible on the storefront. Defaults to true.'),
        ];
    }
}
