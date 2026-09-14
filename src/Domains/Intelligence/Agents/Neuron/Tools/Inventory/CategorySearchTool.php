<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Inventory;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Traits\ListsCatalogReferenceData;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * Neuron counterpart of the Laravel-AI category_search tool — same body via
 * ListsCatalogReferenceData, so both report the full total alongside the page they return.
 */
#[AgentTool(name: 'Category Search', category: 'inventory')]
class CategorySearchTool extends Tool
{
    use HasKanvasContext;
    use ListsCatalogReferenceData;

    public function __construct()
    {
        parent::__construct(
            name: 'category_search',
            description: 'Search the product categories of this company by name, and get their ids. Use this to '
                . 'find the category_ids to pass to set_product_categories. A company can have thousands of '
                . 'categories, so always search by keyword rather than listing them all.',
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
                name: 'keyword',
                type: PropertyType::STRING,
                description: 'Text to match against the category name. Leave empty to see the first page.',
            ),
            new ToolProperty(
                name: 'limit',
                type: PropertyType::INTEGER,
                description: 'How many to return. Defaults to 25, maximum 100.',
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?string $keyword = null, ?int $limit = null): array
    {
        return $this->listCatalogCategories($keyword, $limit);
    }
}
