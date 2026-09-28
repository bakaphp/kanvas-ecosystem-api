<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Inventory;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsAdminForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Traits\ManagesCatalogVariants;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * Adds a variant (one sellable SKU) to an existing product, optionally priced and stocked.
 */
#[AgentTool(name: 'Create Variant', category: 'inventory')]
class CreateVariantTool extends Tool implements HasRunKey
{
    use GuardsAdminForTool;
    use HasKanvasContext;
    use ManagesCatalogVariants;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'create_variant',
            description: 'Add a variant to an existing product — one sellable SKU, e.g. a size or colour. The sku '
                . 'must be unique across the company; use variant_search first to check. Pass price and quantity '
                . 'to stock it at the same time. Use create_product instead when the product itself does not exist '
                . 'yet. Only an administrator can do this.',
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
                description: 'The ID of the product this variant belongs to.',
                required: true,
            ),
            new ToolProperty(
                name: 'name',
                type: PropertyType::STRING,
                description: 'Variant name, e.g. "Large / Black".',
                required: true,
            ),
            new ToolProperty(
                name: 'sku',
                type: PropertyType::STRING,
                description: 'Stock keeping unit. Must be unique across this company.',
                required: true,
            ),
            new ToolProperty(
                name: 'description',
                type: PropertyType::STRING,
                description: 'Description specific to this variant.',
            ),
            new ToolProperty(
                name: 'short_description',
                type: PropertyType::STRING,
                description: 'One-line summary of this variant.',
            ),
            new ToolProperty(
                name: 'ean',
                type: PropertyType::STRING,
                description: 'EAN code of this variant, when known.',
            ),
            new ToolProperty(
                name: 'barcode',
                type: PropertyType::STRING,
                description: 'Barcode of this variant, when known.',
            ),
            new ToolProperty(
                name: 'weight',
                type: PropertyType::NUMBER,
                description: 'Shipping weight of this variant.',
            ),
            new ToolProperty(
                name: 'is_published',
                type: PropertyType::BOOLEAN,
                description: 'Whether the variant is sellable. Defaults to true.',
            ),
            new ToolProperty(
                name: 'price',
                type: PropertyType::NUMBER,
                description: 'Selling price. Omit to leave the variant unpriced.',
            ),
            new ToolProperty(
                name: 'quantity',
                type: PropertyType::NUMBER,
                description: 'Stock on hand. Omit to leave it at zero.',
            ),
            new ToolProperty(
                name: 'warehouse_id',
                type: PropertyType::INTEGER,
                description: 'Warehouse the price and stock apply to. Omit to use the company default warehouse.',
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        int $product_id,
        string $name,
        string $sku,
        ?string $description = null,
        ?string $short_description = null,
        ?string $ean = null,
        ?string $barcode = null,
        ?float $weight = null,
        ?bool $is_published = null,
        ?float $price = null,
        ?float $quantity = null,
        ?int $warehouse_id = null,
    ): array {
        if ($denied = $this->requireAdminOrError()) {
            return $denied;
        }

        return $this->createCatalogVariant(
            productId: $product_id,
            name: $name,
            sku: $sku,
            description: $description,
            shortDescription: $short_description,
            ean: $ean,
            barcode: $barcode,
            weight: $weight,
            isPublished: $is_published,
            price: $price,
            quantity: $quantity,
            warehouseId: $warehouse_id,
        );
    }
}
