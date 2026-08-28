<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Inventory;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsAdminForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Traits\ManagesCatalogProducts;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * Edits a catalog product's own copy fields. Publish state belongs to set_product_published, and
 * price/stock to set_variant_stock — neither is reachable here.
 */
#[AgentTool(name: 'Update Product', category: 'inventory')]
class UpdateProductTool extends Tool implements HasRunKey
{
    use GuardsAdminForTool;
    use HasKanvasContext;
    use ManagesCatalogProducts;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'update_product',
            description: 'Edit an existing product\'s details. Pass only the fields you want to change — anything '
                . 'you omit is left alone. Use list_available_products or inventory_search to get the product_id '
                . 'first. To publish or unpublish it use set_product_published; to change price or stock use '
                . 'set_variant_stock. Only an administrator can do this.',
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
                name: 'product_id',
                type: PropertyType::INTEGER,
                description: 'The ID of the product to edit (from list_available_products or inventory_search).',
                required: true,
            ),
            new ToolProperty(
                name: 'name',
                type: PropertyType::STRING,
                description: 'New product name.',
            ),
            new ToolProperty(
                name: 'description',
                type: PropertyType::STRING,
                description: 'New full description in plain text.',
            ),
            new ToolProperty(
                name: 'short_description',
                type: PropertyType::STRING,
                description: 'New one-line summary used in listings.',
            ),
            new ToolProperty(
                name: 'upc',
                type: PropertyType::STRING,
                description: 'New UPC / barcode.',
            ),
            new ToolProperty(
                name: 'weight',
                type: PropertyType::NUMBER,
                description: 'New shipping weight.',
            ),
            new ToolProperty(
                name: 'warranty_terms',
                type: PropertyType::STRING,
                description: 'New warranty terms text.',
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
        int $product_id,
        ?string $name = null,
        ?string $description = null,
        ?string $short_description = null,
        ?string $upc = null,
        ?float $weight = null,
        ?string $warranty_terms = null,
        ?int $product_type_id = null,
    ): array {
        if ($denied = $this->requireAdminOrError()) {
            return $denied;
        }

        return $this->updateCatalogProduct(
            productId: $product_id,
            name: $name,
            description: $description,
            shortDescription: $short_description,
            upc: $upc,
            weight: $weight,
            warrantyTerms: $warranty_terms,
            productTypeId: $product_type_id,
        );
    }
}
