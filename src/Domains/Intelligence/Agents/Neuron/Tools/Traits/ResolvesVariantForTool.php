<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Inventory\Variants\Models\Variants;

/**
 * Look up a Variant by id from a tool's __invoke and return either the Variant OR a
 * structured error array the LLM can act on. Mirrors ResolvesProductForTool — the lookup is
 * tenant-scoped via getByIdFromCompanyApp, so a foreign id resolves to nothing rather than
 * another tenant's row.
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
    /**
     * @return Variants|array{status: string, message: string}
     */
    protected function resolveVariantOrError(int $variantId): Variants|array
    {
        try {
            /** @var Variants $variant */
            $variant = Variants::getByIdFromCompanyApp($variantId, $this->company, $this->app);

            return $variant;
        } catch (ModelNotFoundException) {
            return [
                'status' => 'error',
                'message' => "Variant {$variantId} does not exist in this company. You invented this variant_id — "
                    . 'never do that. Use variant_search or variant_detail to find the real variant_id, '
                    . 'then retry this tool with it.',
            ];
        }
    }
}
