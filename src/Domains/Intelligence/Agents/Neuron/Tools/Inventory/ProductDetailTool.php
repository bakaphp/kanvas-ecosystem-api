<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Inventory;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Traits\ManagesCatalogProducts;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * The product-level counterpart of variant_detail. The catalog write tools can set categories,
 * attributes and a product type; this is what reads them back.
 */
#[AgentTool(name: 'Product Detail', category: 'inventory')]
class ProductDetailTool extends Tool implements HasRunKey
{
    use HasKanvasContext;
    use ManagesCatalogProducts;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'product_detail',
            description: 'Get the full record of one product: description, product type, categories, attributes, '
                . 'media, and every variant with its stock per warehouse and its selling price per channel. Use '
                . 'this after inventory_search or list_available_products when you need the whole picture, and '
                . 'before editing a product so you know what it already has.',
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
                description: 'The numeric ID of the product to fetch.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $product_id): array
    {
        return $this->detailCatalogProduct($product_id);
    }
}
