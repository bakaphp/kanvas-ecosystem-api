<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Tools\Inventory;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Laravel\Contracts\KanvasToolInterface;
use Kanvas\Intelligence\Agents\Laravel\Traits\GuardsAdminForTool;
use Kanvas\Intelligence\Agents\Laravel\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Traits\ManagesCatalogProducts;
use Laravel\Ai\Tools\Request;
use Override;
use Stringable;

/**
 * Laravel-AI counterpart of the Neuron duplicate_product tool.
 */
#[AgentTool(name: 'Duplicate Product', category: 'inventory')]
class DuplicateProductTool implements KanvasToolInterface
{
    use GuardsAdminForTool;
    use HasKanvasContext;
    use ManagesCatalogProducts;

    public function name(): string
    {
        return 'duplicate_product';
    }

    #[Override]
    public function description(): Stringable|string
    {
        return 'Copy an existing product, with its description, categories, attributes and variants, as a new product '
            . 'named "... (Copy)". Much faster than create_product when the new product is a near-twin of one '
            . 'that exists. The copy carries no stock and no selling price, so follow it with set_variant_stock '
            . 'and set_variant_channel_price, and update_product to rename it. Only an administrator can do this.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $denied = $this->adminDenialFor('duplicate products');

        if ($denied !== null) {
            return $denied;
        }

        return (string) json_encode(
            $this->duplicateCatalogProduct($request->integer('product_id')),
            JSON_PRETTY_PRINT
        );
    }

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'product_id' => $schema
                ->integer()
                ->description('The ID of the product to copy (from inventory_search or list_available_products).')
                ->required(),
        ];
    }
}
