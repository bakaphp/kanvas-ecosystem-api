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
 * Laravel-AI counterpart of the Neuron delete_variant tool — same body via ManagesCatalogVariants.
 */
#[AgentTool(name: 'Delete Variant', category: 'inventory')]
class DeleteVariantTool implements KanvasToolInterface
{
    use GuardsAdminForTool;
    use HasKanvasContext;
    use ManagesCatalogVariants;

    public function name(): string
    {
        return 'delete_variant';
    }

    #[Override]
    public function description(): Stringable|string
    {
        return 'Delete one variant (one SKU) from a product. Refuses when it is the product\'s only variant — use '
            . 'delete_product for that. Prefer update_variant with is_published=false when the intent is only to '
            . 'stop selling it. Use variant_search or variant_detail to get the variant_id first, and confirm with '
            . 'the user before calling this. Only an administrator can do this.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $denied = $this->adminDenialFor('delete variants');

        if ($denied !== null) {
            return $denied;
        }

        return (string) json_encode(
            $this->deleteCatalogVariant($request->integer('variant_id')),
            JSON_PRETTY_PRINT
        );
    }

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'variant_id' => $schema
                ->integer()
                ->description('The ID of the variant to delete (from variant_search or variant_detail).')
                ->required(),
        ];
    }
}
