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
 * Laravel-AI counterpart of the Neuron set_variant_stock tool — same body via ManagesCatalogVariants.
 */
#[AgentTool(name: 'Set Variant Stock', category: 'inventory')]
class SetVariantStockTool implements KanvasToolInterface
{
    use GuardsAdminForTool;
    use HandlesToolRequest;
    use HasKanvasContext;
    use ManagesCatalogVariants;

    public function name(): string
    {
        return 'set_variant_stock';
    }

    #[Override]
    public function description(): Stringable|string
    {
        return 'Set the price, cost and stock quantity of a variant in one warehouse. Price and stock live per '
            . 'warehouse, not on the variant itself, so this is the only way to change them. Pass only what you '
            . 'want to change — omitted values keep their current setting, and every other merchandising flag on '
            . 'the warehouse row is preserved. Use variant_detail first to see the current numbers and which '
            . 'warehouses the variant is in. Only an administrator can do this.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $denied = $this->adminDenialFor('change prices or stock');

        if ($denied !== null) {
            return $denied;
        }

        return (string) json_encode(
            $this->setCatalogVariantStock(
                variantId: $request->integer('variant_id'),
                warehouseId: $this->nullableInt($request, 'warehouse_id'),
                quantity: $this->nullableFloat($request, 'quantity'),
                price: $this->nullableFloat($request, 'price'),
                cost: $this->nullableFloat($request, 'cost'),
                sku: $this->nullableString($request, 'sku'),
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
                ->description('The ID of the variant to price or stock (from variant_search or variant_detail).')
                ->required(),
            'quantity' => $schema
                ->number()
                ->description(
                    'Absolute stock on hand to set — not a delta. Read variant_detail first if you mean to add to '
                    . 'the current stock.'
                ),
            'price' => $schema
                ->number()
                ->description('Selling price in this warehouse.'),
            'cost' => $schema
                ->number()
                ->description('What the company pays for the unit, used for margin reporting.'),
            'sku' => $schema
                ->string()
                ->description('Warehouse-specific SKU, when it differs from the variant SKU. Rarely needed.'),
            'warehouse_id' => $schema
                ->integer()
                ->description('Warehouse to write to. Omit to use the company default warehouse.'),
        ];
    }
}
