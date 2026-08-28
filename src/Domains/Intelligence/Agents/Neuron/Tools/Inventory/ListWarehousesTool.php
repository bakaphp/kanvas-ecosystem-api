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
 * Makes the warehouse_id the stock and pricing tools accept discoverable, so the model asks for a
 * real warehouse instead of inventing an id.
 */
#[AgentTool(name: 'List Warehouses', category: 'inventory')]
class ListWarehousesTool extends Tool
{
    use HasKanvasContext;
    use ListsCatalogReferenceData;

    public function __construct()
    {
        parent::__construct(
            name: 'list_warehouses',
            description: 'List the company\'s warehouses, default first. Stock, cost and warehouse price are held '
                . 'per warehouse, so use this to get a warehouse_id before calling set_variant_stock for a '
                . 'specific location. Omitting warehouse_id on those tools uses the default warehouse.',
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
                name: 'limit',
                type: PropertyType::INTEGER,
                description: 'How many warehouses to return. Defaults to 25, maximum 100.',
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?int $limit = null): array
    {
        return $this->listCatalogWarehouses($limit);
    }
}
