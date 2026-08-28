<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Tools\Inventory;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Laravel\Contracts\KanvasToolInterface;
use Kanvas\Intelligence\Agents\Laravel\Traits\GuardsAdminForTool;
use Kanvas\Intelligence\Agents\Laravel\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Traits\ManagesCatalogVariants;
use Laravel\Ai\Tools\Request;
use Override;
use Stringable;

/**
 * Laravel-AI counterpart of the Neuron duplicate_variant tool.
 */
#[AgentTool(name: 'Duplicate Variant', category: 'inventory')]
class DuplicateVariantTool implements KanvasToolInterface
{
    use GuardsAdminForTool;
    use HasKanvasContext;
    use ManagesCatalogVariants;

    public function name(): string
    {
        return 'duplicate_variant';
    }

    #[Override]
    public function description(): Stringable|string
    {
        return 'Copy an existing variant onto the same product, as a new SKU named "... (Copy)". Use it to add a size '
            . 'or colour that differs from an existing one in only a few fields. The copy has no stock and no '
            . 'price, and its sku is a placeholder — follow up with update_variant to give it a real sku and name, '
            . 'set_variant_stock, and set_variant_channel_price. Only an administrator can do this.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $denied = $this->adminDenialFor('duplicate variants');

        if ($denied !== null) {
            return $denied;
        }

        return (string) json_encode(
            $this->duplicateCatalogVariant($request->integer('variant_id')),
            JSON_PRETTY_PRINT
        );
    }

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'variant_id' => $schema
                ->integer()
                ->description('The ID of the variant to copy (from variant_search or variant_detail).')
                ->required(),
        ];
    }
}
