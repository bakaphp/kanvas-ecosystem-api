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
use Kanvas\Intelligence\Agents\Traits\ManagesCatalogVariants;
use Laravel\Ai\Tools\Request;
use Override;
use Stringable;

/**
 * Laravel-AI counterpart of the Neuron create_product tool — same body via ManagesCatalogProducts.
 */
#[AgentTool(name: 'Create Product', category: 'inventory')]
class CreateProductTool implements KanvasToolInterface
{
    use GuardsAdminForTool;
    use HandlesToolRequest;
    use HasKanvasContext;
    use ManagesCatalogProducts;
    use ManagesCatalogVariants;

    public function name(): string
    {
        return 'create_product';
    }

    #[Override]
    public function description(): Stringable|string
    {
        return 'Create a new product in the inventory catalog. A default variant is created with it, carrying the '
            . 'sku, price and quantity you pass here. The product is created as a draft unless you pass '
            . 'is_published=true — use set_product_published later to put it on the storefront. Search with '
            . 'inventory_search first so you do not create a duplicate. Add further variants with create_variant. '
            . 'Only an administrator can do this.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $denied = $this->adminDenialFor('create products');

        if ($denied !== null) {
            return $denied;
        }

        return (string) json_encode(
            $this->createCatalogProduct(
                name: (string) $request->string('name'),
                description: $this->nullableString($request, 'description'),
                shortDescription: $this->nullableString($request, 'short_description'),
                sku: $this->nullableString($request, 'sku'),
                upc: $this->nullableString($request, 'upc'),
                weight: $this->nullableFloat($request, 'weight'),
                warrantyTerms: $this->nullableString($request, 'warranty_terms'),
                isPublished: $this->nullableBool($request, 'is_published'),
                price: $this->nullableFloat($request, 'price'),
                quantity: $this->nullableFloat($request, 'quantity'),
                warehouseId: $this->nullableInt($request, 'warehouse_id'),
                productTypeId: $this->nullableInt($request, 'product_type_id'),
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
                ->description('The product name as a customer would see it.')
                ->required(),
            'description' => $schema
                ->string()
                ->description('Full product description in plain text.'),
            'short_description' => $schema
                ->string()
                ->description('One-line summary used in listings.'),
            'sku' => $schema
                ->string()
                ->description(
                    'SKU for the default variant. Must be unique in this company. Defaults to a slug of the '
                    . 'product name.'
                ),
            'upc' => $schema
                ->string()
                ->description('UPC / barcode of the product, when known.'),
            'weight' => $schema
                ->number()
                ->description('Shipping weight of the product.'),
            'warranty_terms' => $schema
                ->string()
                ->description('Warranty terms text, when the product has one.'),
            'is_published' => $schema
                ->boolean()
                ->description(
                    'true to publish it on the storefront immediately. Defaults to false (a draft), which is the '
                    . 'safe choice when you are not certain the product is complete.'
                ),
            'price' => $schema
                ->number()
                ->description('Selling price of the default variant. Omit to leave it unpriced.'),
            'quantity' => $schema
                ->number()
                ->description('Stock on hand for the default variant. Omit to leave it at zero.'),
            'warehouse_id' => $schema
                ->integer()
                ->description('Warehouse the price and stock apply to. Omit to use the company default warehouse.'),
            'product_type_id' => $schema
                ->integer()
                ->description(
                    'Product type to file it under (from list_product_types). A product type groups products '
                    . 'sharing a set of attributes.'
                ),
        ];
    }
}
