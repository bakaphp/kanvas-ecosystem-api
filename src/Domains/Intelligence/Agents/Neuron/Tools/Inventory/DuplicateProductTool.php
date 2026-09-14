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

#[AgentTool(name: 'Duplicate Product', category: 'inventory')]
class DuplicateProductTool extends Tool implements HasRunKey
{
    use GuardsAdminForTool;
    use HasKanvasContext;
    use ManagesCatalogProducts;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'duplicate_product',
            description: 'Copy an existing product, with its description, categories, attributes and variants, as '
                . 'a new product named "... (Copy)". Much faster than create_product when the new product is a '
                . 'near-twin of one that exists. The copy carries no stock and no selling price, so follow it with '
                . 'set_variant_stock and set_variant_channel_price, and update_product to rename it. Only an '
                . 'administrator can do this.',
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
                description: 'The ID of the product to copy (from inventory_search or list_available_products).',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $product_id): array
    {
        if ($denied = $this->requireAdminOrError()) {
            return $denied;
        }

        return $this->duplicateCatalogProduct($product_id);
    }
}
