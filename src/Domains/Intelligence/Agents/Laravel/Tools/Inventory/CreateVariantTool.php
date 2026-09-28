<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Tools\Inventory;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Laravel\Contracts\KanvasToolInterface;
use Kanvas\Intelligence\Agents\Laravel\Traits\GuardsAdminForTool;
use Kanvas\Intelligence\Agents\Laravel\Traits\HandlesToolRequest;
use Kanvas\Intelligence\Agents\Laravel\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Traits\ManagesCatalogVariants;
use Laravel\Ai\Tools\Request;
use Override;
use Stringable;

/**
 * Laravel-AI counterpart of the Neuron create_variant tool — same body via ManagesCatalogVariants.
 */
#[AgentTool(name: 'Create Variant', category: 'inventory')]
class CreateVariantTool implements KanvasToolInterface
{
    use GuardsAdminForTool;
    use HandlesToolRequest;
    use HasKanvasContext;
    use ManagesCatalogVariants;

    public function name(): string
    {
        return 'create_variant';
    }

    #[Override]
    public function description(): Stringable|string
    {
        return 'Add a variant to an existing product — one sellable SKU, e.g. a size or colour. The sku must be '
            . 'unique across the company; use variant_search first to check. Pass price and quantity to stock it '
            . 'at the same time. Use create_product instead when the product itself does not exist yet. Only an '
            . 'administrator can do this.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $denied = $this->adminDenialFor('create variants');

        if ($denied !== null) {
            return $denied;
        }

        return (string) json_encode(
            $this->createCatalogVariant(
                productId: $request->integer('product_id'),
                name: (string) $request->string('name'),
                sku: (string) $request->string('sku'),
                description: $this->nullableString($request, 'description'),
                shortDescription: $this->nullableString($request, 'short_description'),
                ean: $this->nullableString($request, 'ean'),
                barcode: $this->nullableString($request, 'barcode'),
                weight: $this->nullableFloat($request, 'weight'),
                isPublished: $this->nullableBool($request, 'is_published'),
                price: $this->nullableFloat($request, 'price'),
                quantity: $this->nullableFloat($request, 'quantity'),
                warehouseId: $this->nullableInt($request, 'warehouse_id'),
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
                ->description('The ID of the product this variant belongs to.')
                ->required(),
            'name' => $schema
                ->string()
                ->description('Variant name, e.g. "Large / Black".')
                ->required(),
            'sku' => $schema
                ->string()
                ->description('Stock keeping unit. Must be unique across this company.')
                ->required(),
            'description' => $schema
                ->string()
                ->description('Description specific to this variant.'),
            'short_description' => $schema
                ->string()
                ->description('One-line summary of this variant.'),
            'ean' => $schema
                ->string()
                ->description('EAN code of this variant, when known.'),
            'barcode' => $schema
                ->string()
                ->description('Barcode of this variant, when known.'),
            'weight' => $schema
                ->number()
                ->description('Shipping weight of this variant.'),
            'is_published' => $schema
                ->boolean()
                ->description('Whether the variant is sellable. Defaults to true.'),
            'price' => $schema
                ->number()
                ->description('Selling price. Omit to leave the variant unpriced.'),
            'quantity' => $schema
                ->number()
                ->description('Stock on hand. Omit to leave it at zero.'),
            'warehouse_id' => $schema
                ->integer()
                ->description('Warehouse the price and stock apply to. Omit to use the company default warehouse.'),
        ];
    }
}
