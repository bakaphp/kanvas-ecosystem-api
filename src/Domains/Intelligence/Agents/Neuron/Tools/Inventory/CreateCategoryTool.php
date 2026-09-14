<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Inventory;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsAdminForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Traits\ManagesCatalogTaxonomy;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

#[AgentTool(name: 'Create Category', category: 'inventory')]
class CreateCategoryTool extends Tool implements HasRunKey
{
    use GuardsAdminForTool;
    use HasKanvasContext;
    use ManagesCatalogTaxonomy;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'create_category',
            description: 'Create a product category. Search with category_search first — a category with the same '
                . 'name is reused rather than duplicated, and a near-duplicate ("Shoes" next to "Shoe") makes the '
                . 'catalog worse. Pass parent_id to nest it under an existing category. Once it exists, file '
                . 'products into it with set_product_categories. Only an administrator can do this.',
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
                description: 'The category name as a shopper would see it.',
                required: true,
            ),
            new ToolProperty(
                name: 'parent_id',
                type: PropertyType::INTEGER,
                description: 'Category to nest this one under, from category_search. Omit for a top-level category.',
            ),
            new ToolProperty(
                name: 'description',
                type: PropertyType::STRING,
                description: 'What belongs in this category.',
            ),
            new ToolProperty(
                name: 'code',
                type: PropertyType::STRING,
                description: 'Internal code for the category, when the company uses one.',
            ),
            new ToolProperty(
                name: 'is_published',
                type: PropertyType::BOOLEAN,
                description: 'Whether the category is visible on the storefront. Defaults to true.',
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        string $name,
        ?int $parent_id = null,
        ?string $description = null,
        ?string $code = null,
        ?bool $is_published = null,
    ): array {
        if ($denied = $this->requireAdminOrError()) {
            return $denied;
        }

        return $this->createCatalogCategory(
            name: $name,
            parentId: $parent_id,
            description: $description,
            code: $code,
            isPublished: $is_published,
        );
    }
}
