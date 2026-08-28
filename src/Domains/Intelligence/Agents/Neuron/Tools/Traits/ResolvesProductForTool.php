<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Kanvas\Intelligence\Agents\Traits\ResolvesCatalogEntities;
use Kanvas\Inventory\Products\Models\Products;

/**
 * Look up a Product by id from a tool's __invoke and return either the Product OR a
 * structured error array the LLM can act on. Prevents a hallucinated product_id from
 * crashing the chat with an unhandled ModelNotFoundException.
 *
 * The lookup itself lives in ResolvesCatalogEntities so the Laravel-AI catalog tools share it; this
 * trait is the Neuron-side name for it. Requires HasKanvasContext (uses $this->app / $this->company)
 * — the lookup is tenant-scoped via getByIdFromCompanyApp, so a foreign id resolves to nothing
 * rather than another tenant's row.
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
    use ResolvesCatalogEntities;

    /**
     * @return Products|array{status: string, message: string}
     */
    protected function resolveProductOrError(int $productId): Products|array
    {
        return $this->resolveCatalogProduct($productId);
    }
}
