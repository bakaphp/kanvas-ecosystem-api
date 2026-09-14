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
 * Neuron counterpart of the Laravel-AI attribute_search tool — same body via
 * ListsCatalogReferenceData.
 */
#[AgentTool(name: 'Attribute Search', category: 'inventory')]
class AttributeSearchTool extends Tool
{
    use HasKanvasContext;
    use ListsCatalogReferenceData;

    public function __construct()
    {
        parent::__construct(
            name: 'attribute_search',
            description: 'Search the product attributes this company can use — the spec fields like Colour, Size '
                . 'or Material — and see which values each one allows. Use this before set_product_attributes or '
                . 'set_variant_attributes to reuse an existing attribute name and its allowed values rather than '
                . 'inventing a new near-duplicate. A company can have thousands, so search by keyword.',
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
                description: 'Text to match against the attribute name. Leave empty to see the first page.',
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
        return $this->listCatalogAttributes($keyword, $limit);
    }
}
