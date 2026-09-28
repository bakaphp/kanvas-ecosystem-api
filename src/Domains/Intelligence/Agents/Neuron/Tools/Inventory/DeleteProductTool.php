<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Inventory;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsAdminForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Traits\ManagesCatalogProducts;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * Soft-deletes a product and, by the model's cascade, its variants. The description steers the model
 * to set_product_published for the reversible "take it down" case, which is what is actually being
 * asked for most of the time someone says "remove this product".
 *
 * No TrackByInputs here, unlike the create/update tools: the per-tool-name run cap is the throttle we
 * want on a destructive op, so a turn can delete at most 10 products however many ids it invents.
 */
#[AgentTool(name: 'Delete Product', category: 'inventory')]
class DeleteProductTool extends Tool
{
    use GuardsAdminForTool;
    use HasKanvasContext;
    use ManagesCatalogProducts;

    public function __construct()
    {
        parent::__construct(
            name: 'delete_product',
            description: 'Delete a product from the catalog, along with all of its variants. Prefer '
                . 'set_product_published with published=false when the intent is only to take the product off the '
                . 'storefront — that is reversible and keeps its sales history readable. Use '
                . 'list_available_products or inventory_search to get the product_id first, and confirm with the '
                . 'user before calling this. Only an administrator can do this.',
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
                description: 'The ID of the product to delete (from list_available_products or inventory_search).',
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

        return $this->deleteCatalogProduct($product_id);
    }
}
