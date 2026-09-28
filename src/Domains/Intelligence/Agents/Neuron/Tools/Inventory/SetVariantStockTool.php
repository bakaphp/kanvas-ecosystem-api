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
 * Writes price, cost and stock for a variant in one warehouse — the columns that decide what a
 * customer pays and whether the variant can be bought at all.
 */
#[AgentTool(name: 'Set Variant Stock', category: 'inventory')]
class SetVariantStockTool extends Tool implements HasRunKey
{
    use GuardsAdminForTool;
    use HasKanvasContext;
    use ManagesCatalogVariants;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'set_variant_stock',
            description: 'Set the price, cost and stock quantity of a variant in one warehouse. Price and stock '
                . 'live per warehouse, not on the variant itself, so this is the only way to change them. Pass '
                . 'only what you want to change — omitted values keep their current setting, and every other '
                . 'merchandising flag on the warehouse row is preserved. Use variant_detail first to see the '
                . 'current numbers and which warehouses the variant is in. Only an administrator can do this.',
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
                name: 'variant_id',
                type: PropertyType::INTEGER,
                description: 'The ID of the variant to price or stock (from variant_search or variant_detail).',
                required: true,
            ),
            new ToolProperty(
                name: 'quantity',
                type: PropertyType::NUMBER,
                description: 'Absolute stock on hand to set — not a delta. Read variant_detail first if you mean '
                    . 'to add to the current stock.',
            ),
            new ToolProperty(
                name: 'price',
                type: PropertyType::NUMBER,
                description: 'Selling price in this warehouse.',
            ),
            new ToolProperty(
                name: 'cost',
                type: PropertyType::NUMBER,
                description: 'What the company pays for the unit, used for margin reporting.',
            ),
            new ToolProperty(
                name: 'sku',
                type: PropertyType::STRING,
                description: 'Warehouse-specific SKU, when it differs from the variant SKU. Rarely needed.',
            ),
            new ToolProperty(
                name: 'warehouse_id',
                type: PropertyType::INTEGER,
                description: 'Warehouse to write to. Omit to use the company default warehouse.',
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        int $variant_id,
        ?float $quantity = null,
        ?float $price = null,
        ?float $cost = null,
        ?string $sku = null,
        ?int $warehouse_id = null,
    ): array {
        if ($denied = $this->requireAdminOrError()) {
            return $denied;
        }

        return $this->setCatalogVariantStock(
            variantId: $variant_id,
            warehouseId: $warehouse_id,
            quantity: $quantity,
            price: $price,
            cost: $cost,
            sku: $sku,
        );
    }
}
