<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Inventory;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\DecodesJsonObjectParam;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsAdminForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Traits\ManagesCatalogVariants;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\ToolPropertyInterface;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * Sets the spec fields that distinguish one SKU from another — the size/colour axis a storefront
 * filters and pickers are built from.
 */
#[AgentTool(name: 'Set Variant Attributes', category: 'inventory')]
class SetVariantAttributesTool extends Tool implements HasRunKey
{
    use DecodesJsonObjectParam;
    use GuardsAdminForTool;
    use HasKanvasContext;
    use ManagesCatalogVariants;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'set_variant_attributes',
            description: 'Set spec attributes on one variant — the facts that differ per SKU, e.g. Size or Colour. '
                . 'Pass a JSON object of name to value. An attribute that does not exist yet is created. '
                . 'Attributes you do not mention are left alone. Check attribute_search first so you reuse the '
                . 'company\'s existing attribute names. For a fact shared by every SKU of the product use '
                . 'set_product_attributes instead. Only an administrator can do this.',
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
                name: 'variant_id',
                type: PropertyType::INTEGER,
                description: 'The ID of the variant (from variant_search or variant_detail).',
                required: true,
            ),
            new ToolProperty(
                name: 'attributes',
                type: PropertyType::STRING,
                description: 'A JSON object mapping attribute name to value, passed as a string. For example: '
                    . '{"Size": "XL", "Colour": "Black"}',
            ),
            new ArrayProperty(
                name: 'remove',
                description: 'Attribute names to remove from the variant entirely. Setting a value to an empty string '
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
    public function __invoke(int $variant_id, array|string|null $attributes = null, ?array $remove = null): array
    {
        if ($denied = $this->requireAdminOrError()) {
            return $denied;
        }

        return $this->setCatalogVariantAttributes(
            variantId: $variant_id,
            attributes: $this->decodeJsonObjectParam($attributes),
            remove: $remove ?? [],
        );
    }
}
