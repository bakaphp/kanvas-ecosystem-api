<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Tools\Inventory;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Laravel\Contracts\KanvasToolInterface;
use Kanvas\Intelligence\Agents\Laravel\Traits\GuardsAdminForTool;
use Kanvas\Intelligence\Agents\Laravel\Traits\HandlesToolRequest;
use Kanvas\Intelligence\Agents\Laravel\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Traits\ManagesCatalogVariants;
use Laravel\Ai\Tools\Request;
use Override;
use Stringable;

/**
 * Laravel-AI counterpart of the Neuron update_variant tool — same body via ManagesCatalogVariants.
 */
#[AgentTool(name: 'Update Variant', category: 'inventory')]
class UpdateVariantTool implements KanvasToolInterface
{
    use GuardsAdminForTool;
    use HandlesToolRequest;
    use HasKanvasContext;
    use ManagesCatalogVariants;

    public function name(): string
    {
        return 'update_variant';
    }

    #[Override]
    public function description(): Stringable|string
    {
        return 'Edit an existing variant. Pass only the fields you want to change — anything you omit is left '
            . 'alone. Use variant_search or variant_detail to get the variant_id first. To change price, cost or '
            . 'stock use set_variant_stock instead. Only an administrator can do this.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $denied = $this->adminDenialFor('edit variants');

        if ($denied !== null) {
            return $denied;
        }

        return (string) json_encode(
            $this->updateCatalogVariant(
                variantId: $request->integer('variant_id'),
                name: $this->nullableString($request, 'name'),
                sku: $this->nullableString($request, 'sku'),
                description: $this->nullableString($request, 'description'),
                shortDescription: $this->nullableString($request, 'short_description'),
                ean: $this->nullableString($request, 'ean'),
                barcode: $this->nullableString($request, 'barcode'),
                weight: $this->nullableFloat($request, 'weight'),
                isPublished: $this->nullableBool($request, 'is_published'),
            ),
            JSON_PRETTY_PRINT
        );
    }

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'variant_id' => $schema
                ->integer()
                ->description('The ID of the variant to edit (from variant_search or variant_detail).')
                ->required(),
            'name' => $schema
                ->string()
                ->description('New variant name.'),
            'sku' => $schema
                ->string()
                ->description('New SKU. Must stay unique across this company.'),
            'description' => $schema
                ->string()
                ->description('New description for this variant.'),
            'short_description' => $schema
                ->string()
                ->description('New one-line summary of this variant.'),
            'ean' => $schema
                ->string()
                ->description('New EAN code.'),
            'barcode' => $schema
                ->string()
                ->description('New barcode.'),
            'weight' => $schema
                ->number()
                ->description('New shipping weight.'),
            'is_published' => $schema
                ->boolean()
                ->description('true to make the variant sellable, false to take it out of the storefront.'),
        ];
    }
}
