<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Inventory;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsAdminForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesProductForTool;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/**
 * Publish or unpublish a product in the catalog. Unpublishing takes it off the storefront /
 * search index (it stops being searchable); publishing puts it back and stamps published_at.
 * Company-wide privileged write — gated on the requesting human being an admin. The product is
 * resolved tenant-scoped, so a foreign/hallucinated id can never flip another tenant's row.
 */
#[AgentTool(name: 'Set Product Published', category: 'inventory')]
class SetProductPublishedTool extends Tool
{
    use GuardsAdminForTool;
    use HasKanvasContext;
    use ResolvesProductForTool;

    public function __construct()
    {
        parent::__construct(
            name: 'set_product_published',
            description: 'Publish or unpublish a product. Provide the product_id and published=false to take the '
                . 'product off the storefront (unpublish / make it a draft, removed from search), or published=true '
                . 'to publish it again. Use list_available_products first to find the product_id. Only an '
                . 'administrator can do this.',
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
                description: 'The ID of the product to publish/unpublish (from list_available_products).',
                required: true,
            ),
            new ToolProperty(
                name: 'published',
                type: PropertyType::BOOLEAN,
                description: 'true = publish the product, false = unpublish it (take it off the storefront).',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $product_id, bool $published): array
    {
        if ($denied = $this->requireAdminOrError()) {
            return $denied;
        }

        $result = $this->resolveProductOrError($product_id);
        if (is_array($result)) {
            return $result;
        }
        $product = $result;

        if ($product->is_published === $published) {
            return [
                'updated' => false,
                'product_id' => $product->getId(),
                'name' => $product->name,
                'is_published' => $published,
                'message' => sprintf('Product "%s" is already %s.', $product->name, $published ? 'published' : 'unpublished'),
            ];
        }

        try {
            $published ? $product->publish() : $product->unPublish();
        } catch (Throwable $e) {
            report($e);

            return [
                'updated' => false,
                'message' => 'Could not update the product\'s published status. Do not retry.',
            ];
        }

        return [
            'updated' => true,
            'product_id' => $product->getId(),
            'name' => $product->name,
            'is_published' => $product->is_published,
            'message' => sprintf('Product "%s" is now %s.', $product->name, $published ? 'published' : 'unpublished'),
        ];
    }
}
