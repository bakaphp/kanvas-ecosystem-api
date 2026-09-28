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

#[AgentTool(name: 'List Product Types', category: 'inventory')]
class ListProductTypesTool extends Tool
{
    use HasKanvasContext;
    use ListsCatalogReferenceData;

    public function __construct()
    {
        parent::__construct(
            name: 'list_product_types',
            description: 'List the product types available to this company, including the app-wide ones. A product '
                . 'type groups products that share a set of attributes; use this to get a product_type_id for '
                . 'create_product or update_product.',
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
                description: 'Optional text to filter product types by name.',
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
        return $this->listCatalogProductTypes($keyword, $limit);
    }
}
