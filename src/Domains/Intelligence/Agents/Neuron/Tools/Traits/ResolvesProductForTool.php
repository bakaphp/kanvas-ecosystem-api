<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Inventory\Products\Models\Products;

/**
 * Look up a Product by id from a tool's __invoke and return either the Product OR a
 * structured error array the LLM can act on. Prevents a hallucinated product_id from
 * crashing the chat with an unhandled ModelNotFoundException.
 *
 * Requires HasKanvasContext (uses $this->app / $this->company) — the lookup is tenant-scoped
 * via getByIdFromCompanyApp, so a foreign id resolves to nothing rather than another tenant's row.
 *
 * Pattern:
 *
 *   $result = $this->resolveProductOrError($product_id);
 *   if (is_array($result)) {
 *       return $result;     // tool returns the structured error to Neuron
 *   }
 *   $product = $result;     // typed Products from here on
 */
trait ResolvesProductForTool
{
    /**
     * @return Products|array{status: string, message: string}
     */
    protected function resolveProductOrError(int $productId): Products|array
    {
        try {
            /** @var Products $product */
            $product = Products::getByIdFromCompanyApp($productId, $this->company, $this->app);

            return $product;
        } catch (ModelNotFoundException) {
            return [
                'status' => 'error',
                'message' => "Product {$productId} does not exist in this company. You invented this product_id — "
                    . 'never do that. Use list_available_products or a product search to find the real product_id, '
                    . 'then retry this tool with it.',
            ];
        }
    }
}
