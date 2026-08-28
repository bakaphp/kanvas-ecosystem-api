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
 * Laravel-AI counterpart of the Neuron delete_product tool — same body via ManagesCatalogProducts.
 */
#[AgentTool(name: 'Delete Product', category: 'inventory')]
class DeleteProductTool implements KanvasToolInterface
{
    use GuardsAdminForTool;
    use HasKanvasContext;
    use ManagesCatalogProducts;

    public function name(): string
    {
        return 'delete_product';
    }

    #[Override]
    public function description(): Stringable|string
    {
        return 'Delete a product from the catalog, along with all of its variants. Prefer set_product_published '
            . 'with published=false when the intent is only to take the product off the storefront — that is '
            . 'reversible and keeps its sales history readable. Use list_available_products or inventory_search to '
            . 'get the product_id first, and confirm with the user before calling this. Only an administrator can '
            . 'do this.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $denied = $this->adminDenialFor('delete products');

        if ($denied !== null) {
            return $denied;
        }

        return (string) json_encode(
            $this->deleteCatalogProduct($request->integer('product_id')),
            JSON_PRETTY_PRINT
        );
    }

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'product_id' => $schema
                ->integer()
                ->description('The ID of the product to delete (from list_available_products or inventory_search).')
                ->required(),
        ];
    }
}
