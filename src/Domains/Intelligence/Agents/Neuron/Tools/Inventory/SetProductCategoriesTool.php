<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Inventory;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsAdminForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Traits\ManagesCatalogProducts;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\ToolPropertyInterface;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * Files a product under categories. Adds by default rather than replacing: an LLM rarely knows the
 * product's existing categories, and a replace it did not intend silently unfiles the product from
 * every category a merchant put it in.
 */
#[AgentTool(name: 'Set Product Categories', category: 'inventory')]
class SetProductCategoriesTool extends Tool implements HasRunKey
{
    use GuardsAdminForTool;
    use HasKanvasContext;
    use ManagesCatalogProducts;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'set_product_categories',
            description: 'File a product under one or more categories. By default the categories you pass are '
                . 'added to whatever the product already has; pass replace=true only when you mean to discard its '
                . 'current categories. Use category_search to find the ids first — never guess them. Only an '
                . 'administrator can do this.',
        );
    }

    /**
     * @return array<int, ToolPropertyInterface>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'product_id',
                type: PropertyType::INTEGER,
                description: 'The ID of the product to file (from list_available_products or inventory_search).',
                required: true,
            ),
            new ArrayProperty(
                name: 'category_ids',
                description: 'The category ids to file the product under, from category_search.',
                required: true,
                items: new ToolProperty(
                    name: 'category_id',
                    type: PropertyType::INTEGER,
                    description: 'A category id.',
                ),
            ),
            new ToolProperty(
                name: 'replace',
                type: PropertyType::BOOLEAN,
                description: 'true to discard the product\'s current categories and keep only the ones you pass. '
                    . 'Defaults to false, which adds to them.',
            ),
        ];
    }

    /**
     * @param array<int, int|string> $category_ids
     * @return array<string, mixed>
     */
    public function __invoke(int $product_id, array $category_ids, ?bool $replace = null): array
    {
        if ($denied = $this->requireAdminOrError()) {
            return $denied;
        }

        return $this->setCatalogProductCategories(
            productId: $product_id,
            categoryIds: array_map('intval', $category_ids),
            replace: $replace ?? false,
        );
    }
}
