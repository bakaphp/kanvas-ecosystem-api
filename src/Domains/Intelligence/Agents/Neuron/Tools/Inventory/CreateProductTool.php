<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Inventory;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsAdminForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Traits\ManagesCatalogProducts;
use Kanvas\Intelligence\Agents\Traits\ManagesCatalogVariants;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * Creates a catalog product, its default variant, and optionally that variant's price and stock.
 * Company-wide privileged write — gated on the requesting human being an admin, like
 * set_product_published.
 */
#[AgentTool(name: 'Create Product', category: 'inventory')]
class CreateProductTool extends Tool implements HasRunKey
{
    use GuardsAdminForTool;
    use HasKanvasContext;
    use ManagesCatalogProducts;
    use ManagesCatalogVariants;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'create_product',
            description: 'Create a new product in the inventory catalog. A default variant is created with it, '
                . 'carrying the sku, price and quantity you pass here. The product is created as a draft unless '
                . 'you pass is_published=true — use set_product_published later to put it on the storefront. '
                . 'Search with inventory_search first so you do not create a duplicate. Add further variants with '
                . 'create_variant. Only an administrator can do this.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'name',
                type: PropertyType::STRING,
                description: 'The product name as a customer would see it.',
                required: true,
            ),
            new ToolProperty(
                name: 'description',
                type: PropertyType::STRING,
                description: 'Full product description in plain text.',
            ),
            new ToolProperty(
                name: 'short_description',
                type: PropertyType::STRING,
                description: 'One-line summary used in listings.',
            ),
            new ToolProperty(
                name: 'sku',
                type: PropertyType::STRING,
                description: 'SKU for the default variant. Must be unique in this company. Defaults to a slug of '
                    . 'the product name.',
            ),
            new ToolProperty(
                name: 'upc',
                type: PropertyType::STRING,
                description: 'UPC / barcode of the product, when known.',
            ),
            new ToolProperty(
                name: 'weight',
                type: PropertyType::NUMBER,
                description: 'Shipping weight of the product.',
            ),
            new ToolProperty(
                name: 'warranty_terms',
                type: PropertyType::STRING,
                description: 'Warranty terms text, when the product has one.',
            ),
            new ToolProperty(
                name: 'is_published',
                type: PropertyType::BOOLEAN,
                description: 'true to publish it on the storefront immediately. Defaults to false (a draft), which '
                    . 'is the safe choice when you are not certain the product is complete.',
            ),
            new ToolProperty(
                name: 'price',
                type: PropertyType::NUMBER,
                description: 'Selling price of the default variant. Omit to leave it unpriced.',
            ),
            new ToolProperty(
                name: 'quantity',
                type: PropertyType::NUMBER,
                description: 'Stock on hand for the default variant. Omit to leave it at zero.',
            ),
            new ToolProperty(
                name: 'warehouse_id',
                type: PropertyType::INTEGER,
                description: 'Warehouse the price and stock apply to. Omit to use the company default warehouse.',
            ),
            new ToolProperty(
                name: 'product_type_id',
                type: PropertyType::INTEGER,
                description: 'Product type to file it under (from list_product_types). A product type groups '
                    . 'products sharing a set of attributes.',
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        string $name,
        ?string $description = null,
        ?string $short_description = null,
        ?string $sku = null,
        ?string $upc = null,
        ?float $weight = null,
        ?string $warranty_terms = null,
        ?bool $is_published = null,
        ?float $price = null,
        ?float $quantity = null,
        ?int $warehouse_id = null,
        ?int $product_type_id = null,
    ): array {
        if ($denied = $this->requireAdminOrError()) {
            return $denied;
        }

        return $this->createCatalogProduct(
            name: $name,
            description: $description,
            shortDescription: $short_description,
            sku: $sku,
            upc: $upc,
            weight: $weight,
            warrantyTerms: $warranty_terms,
            isPublished: $is_published,
            price: $price,
            quantity: $quantity,
            warehouseId: $warehouse_id,
            productTypeId: $product_type_id,
        );
    }
}
