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

#[AgentTool(name: 'Create Product Type', category: 'inventory')]
class CreateProductTypeTool extends Tool implements HasRunKey
{
    use GuardsAdminForTool;
    use HasKanvasContext;
    use ManagesCatalogTaxonomy;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'create_product_type',
            description: 'Create a product type — the grouping for products that share a set of attributes, e.g. '
                . '"Footwear" or "Laptop". Check list_product_types first; a type with the same name is reused '
                . 'rather than duplicated. Pass the new id as product_type_id on create_product or update_product. '
                . 'Only an administrator can do this.',
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
                description: 'The product type name.',
                required: true,
            ),
            new ToolProperty(
                name: 'description',
                type: PropertyType::STRING,
                description: 'What kind of products belong to this type.',
            ),
            new ToolProperty(
                name: 'is_published',
                type: PropertyType::BOOLEAN,
                description: 'Whether the type is active. Defaults to true.',
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $name, ?string $description = null, ?bool $is_published = null): array
    {
        if ($denied = $this->requireAdminOrError()) {
            return $denied;
        }

        return $this->createCatalogProductType(
            name: $name,
            description: $description,
            isPublished: $is_published,
        );
    }
}
