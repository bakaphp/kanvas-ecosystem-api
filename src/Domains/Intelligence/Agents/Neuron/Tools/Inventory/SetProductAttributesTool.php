<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Inventory;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\DecodesJsonObjectParam;
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
 * Sets the spec fields shared by every variant of a product. Attribute names are resolved by name
 * and created when new, so the model never needs an attribute id.
 */
#[AgentTool(name: 'Set Product Attributes', category: 'inventory')]
class SetProductAttributesTool extends Tool implements HasRunKey
{
    use DecodesJsonObjectParam;
    use GuardsAdminForTool;
    use HasKanvasContext;
    use ManagesCatalogProducts;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'set_product_attributes',
            description: 'Set spec attributes on a product — the facts shared by every variant, e.g. Material, '
                . 'Brand or Warranty. Pass a JSON object of name to value. An attribute that does not exist yet is '
                . 'created. Attributes you do not mention are left alone. Check attribute_search first so you '
                . 'reuse the company\'s existing attribute names instead of creating near-duplicates. For a fact '
                . 'that differs per SKU (size, colour) use set_variant_attributes instead. Only an administrator '
                . 'can do this.',
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
                description: 'The ID of the product (from list_available_products or inventory_search).',
                required: true,
            ),
            new ToolProperty(
                name: 'attributes',
                type: PropertyType::STRING,
                description: 'A JSON object mapping attribute name to value, passed as a string. For example: '
                    . '{"Material": "Cotton", "Brand": "Acme", "Warranty": "2 years"}',
            ),
            new ArrayProperty(
                name: 'remove',
                description: 'Attribute names to remove from the product entirely. Setting a value to an empty string '
                    . 'does NOT remove it — name it here instead.',
                required: false,
                items: new ToolProperty(
                    name: 'attribute_name',
                    type: PropertyType::STRING,
                    description: 'An attribute name, exactly as attribute_search spells it.',
                ),
            ),
        ];
    }

    /**
     * @param array<int, string>|null $remove
     * @return array<string, mixed>
     */
    public function __invoke(int $product_id, array|string|null $attributes = null, ?array $remove = null): array
    {
        if ($denied = $this->requireAdminOrError()) {
            return $denied;
        }

        return $this->setCatalogProductAttributes(
            productId: $product_id,
            attributes: $this->decodeJsonObjectParam($attributes),
            remove: $remove ?? [],
        );
    }
}
