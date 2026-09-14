<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Inventory;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsAdminForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Traits\ManagesCatalogVariants;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

#[AgentTool(name: 'Duplicate Variant', category: 'inventory')]
class DuplicateVariantTool extends Tool implements HasRunKey
{
    use GuardsAdminForTool;
    use HasKanvasContext;
    use ManagesCatalogVariants;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'duplicate_variant',
            description: 'Copy an existing variant onto the same product, as a new SKU named "... (Copy)". Use it '
                . 'to add a size or colour that differs from an existing one in only a few fields. The copy has no '
                . 'stock and no price, and its sku is a placeholder — follow up with update_variant to give it a '
                . 'real sku and name, set_variant_stock, and set_variant_channel_price. Only an administrator can '
                . 'do this.',
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
                name: 'variant_id',
                type: PropertyType::INTEGER,
                description: 'The ID of the variant to copy (from variant_search or variant_detail).',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $variant_id): array
    {
        if ($denied = $this->requireAdminOrError()) {
            return $denied;
        }

        return $this->duplicateCatalogVariant($variant_id);
    }
}
