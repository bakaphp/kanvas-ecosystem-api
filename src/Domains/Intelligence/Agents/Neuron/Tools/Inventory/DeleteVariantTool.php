<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Inventory;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsAdminForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Traits\ManagesCatalogVariants;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * Soft-deletes one variant. Refuses on a product's last variant — see the guard in
 * ManagesCatalogVariants for why.
 *
 * No TrackByInputs, for the same reason as delete_product: the per-tool-name run cap is a deliberate
 * ceiling on how much a single turn can destroy.
 */
#[AgentTool(name: 'Delete Variant', category: 'inventory')]
class DeleteVariantTool extends Tool
{
    use GuardsAdminForTool;
    use HasKanvasContext;
    use ManagesCatalogVariants;

    public function __construct()
    {
        parent::__construct(
            name: 'delete_variant',
            description: 'Delete one variant (one SKU) from a product. Refuses when it is the product\'s only '
                . 'variant — use delete_product for that. Prefer update_variant with is_published=false when the '
                . 'intent is only to stop selling it. Use variant_search or variant_detail to get the variant_id '
                . 'first, and confirm with the user before calling this. Only an administrator can do this.',
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
                description: 'The ID of the variant to delete (from variant_search or variant_detail).',
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

        return $this->deleteCatalogVariant($variant_id);
    }
}
