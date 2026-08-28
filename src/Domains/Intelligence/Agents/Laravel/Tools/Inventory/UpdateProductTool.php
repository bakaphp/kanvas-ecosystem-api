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
 * Laravel-AI counterpart of the Neuron update_product tool — same body via ManagesCatalogProducts.
 */
#[AgentTool(name: 'Update Product', category: 'inventory')]
class UpdateProductTool implements KanvasToolInterface
{
    use GuardsAdminForTool;
    use HandlesToolRequest;
    use HasKanvasContext;
    use ManagesCatalogProducts;

    public function name(): string
    {
        return 'update_product';
    }

    #[Override]
    public function description(): Stringable|string
    {
        return 'Edit an existing product\'s details. Pass only the fields you want to change — anything you omit '
            . 'is left alone. Use list_available_products or inventory_search to get the product_id first. To '
            . 'publish or unpublish it use set_product_published; to change price or stock use set_variant_stock. '
            . 'Only an administrator can do this.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $denied = $this->adminDenialFor('edit products');

        if ($denied !== null) {
            return $denied;
        }

        return (string) json_encode(
            $this->updateCatalogProduct(
                productId: $request->integer('product_id'),
                name: $this->nullableString($request, 'name'),
                description: $this->nullableString($request, 'description'),
                shortDescription: $this->nullableString($request, 'short_description'),
                upc: $this->nullableString($request, 'upc'),
                weight: $this->nullableFloat($request, 'weight'),
                warrantyTerms: $this->nullableString($request, 'warranty_terms'),
                productTypeId: $this->nullableInt($request, 'product_type_id'),
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
                ->description('The ID of the product to edit (from list_available_products or inventory_search).')
                ->required(),
            'name' => $schema
                ->string()
                ->description('New product name.'),
            'description' => $schema
                ->string()
                ->description('New full description in plain text.'),
            'short_description' => $schema
                ->string()
                ->description('New one-line summary used in listings.'),
            'upc' => $schema
                ->string()
                ->description('New UPC / barcode.'),
            'weight' => $schema
                ->number()
                ->description('New shipping weight.'),
            'warranty_terms' => $schema
                ->string()
                ->description('New warranty terms text.'),
            'product_type_id' => $schema
                ->integer()
                ->description(
                    'Product type to file it under (from list_product_types). A product type groups products '
                    . 'sharing a set of attributes.'
                ),
        ];
    }
}
