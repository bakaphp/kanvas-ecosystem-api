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
 * Laravel-AI counterpart of the Neuron set_variant_channel_price tool.
 */
#[AgentTool(name: 'Set Variant Channel Price', category: 'inventory')]
class SetVariantChannelPriceTool implements KanvasToolInterface
{
    use GuardsAdminForTool;
    use HandlesToolRequest;
    use HasKanvasContext;
    use ManagesCatalogVariants;

    public function name(): string
    {
        return 'set_variant_channel_price';
    }

    #[Override]
    public function description(): Stringable|string
    {
        return 'Set the selling price of a variant on a sales channel — this is the price a customer actually pays '
            . 'at checkout, which is NOT the warehouse price set_variant_stock writes. Use discounted_price for a '
            . 'sale price and leave price as the list price. Pass only what you want to change. Use variant_detail '
            . 'to see current channel prices and list_channels to see the channels. Publishing a channel price for '
            . 'the first time also publishes the parent product if it is still a draft. Only an administrator can '
            . 'do this.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $denied = $this->adminDenialFor('change selling prices');

        if ($denied !== null) {
            return $denied;
        }

        return (string) json_encode(
            $this->setCatalogVariantChannelPrice(
                variantId: $request->integer('variant_id'),
                price: $this->nullableFloat($request, 'price'),
                discountedPrice: $this->nullableFloat($request, 'discounted_price'),
                isPublished: $this->nullableBool($request, 'is_published'),
                channelId: $this->nullableInt($request, 'channel_id'),
                warehouseId: $this->nullableInt($request, 'warehouse_id'),
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
                ->description('The ID of the variant to price (from variant_search or variant_detail).')
                ->required(),
            'price' => $schema
                ->number()
                ->description('List price on this channel.'),
            'discounted_price' => $schema
                ->number()
                ->description(
                    'Sale price. When set and non-zero this is what the customer pays. Set it to 0 to end a sale '
                    . 'and go back to the list price.'
                ),
            'is_published' => $schema
                ->boolean()
                ->description('Whether the variant is listed on this channel. Defaults to leaving it as it is.'),
            'channel_id' => $schema
                ->integer()
                ->description(
                    'Channel to price on (from list_channels). Omit to use the default channel, which is the one '
                    . 'the cart reads.'
                ),
            'warehouse_id' => $schema
                ->integer()
                ->description(
                    'Warehouse the price belongs to — channel prices hang off a warehouse row. Omit to use the '
                    . 'company default warehouse.'
                ),
        ];
    }
}
