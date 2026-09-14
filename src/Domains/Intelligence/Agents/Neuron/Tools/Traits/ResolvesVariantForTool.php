<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Kanvas\Intelligence\Agents\Traits\ResolvesCatalogEntities;
use Kanvas\Inventory\Variants\Models\Variants;

/**
 * Look up a Variant by id from a tool's __invoke and return either the Variant OR a
 * structured error array the LLM can act on. Mirrors ResolvesProductForTool — the lookup is
 * tenant-scoped via getByIdFromCompanyApp, so a foreign id resolves to nothing rather than
 * another tenant's row, and it lives in ResolvesCatalogEntities so the Laravel-AI catalog tools
 * share the same body.
 *
 * Pattern:
 *
 *   $result = $this->resolveVariantOrError($variant_id);
 *   if (is_array($result)) {
 *       return $result;     // tool returns the structured error to Neuron
 *   }
 *   $variant = $result;     // typed Variants from here on
 */
trait ResolvesVariantForTool
{
    use ResolvesCatalogEntities;

    /**
     * @return Variants|array{status: string, message: string}
     */
    protected function resolveVariantOrError(int $variantId): Variants|array
    {
        return $this->resolveCatalogVariant($variantId);
    }
}
