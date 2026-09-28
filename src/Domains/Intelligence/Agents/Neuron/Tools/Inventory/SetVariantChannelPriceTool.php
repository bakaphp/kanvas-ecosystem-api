<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Inventory;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsAdminForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Traits\ManagesCatalogVariants;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * Writes the price a customer actually pays. AddToCartAction resolves the cart price from the
 * variants_channels pivot, not from the warehouse row set_variant_stock writes, so this is the tool
 * that decides what a storefront charges.
 */
#[AgentTool(name: 'Set Variant Channel Price', category: 'inventory')]
class SetVariantChannelPriceTool extends Tool implements HasRunKey
{
    use GuardsAdminForTool;
    use HasKanvasContext;
    use ManagesCatalogVariants;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'set_variant_channel_price',
            description: 'Set the selling price of a variant on a sales channel — this is the price a customer '
                . 'actually pays at checkout, which is NOT the warehouse price set_variant_stock writes. Use '
                . 'discounted_price for a sale price and leave price as the list price. Pass only what you want to '
                . 'change. Use variant_detail to see current channel prices and list_channels to see the channels. '
                . 'Publishing a channel price for the first time also publishes the parent product if it is still '
                . 'a draft. Only an administrator can do this.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'variant_id',
                type: PropertyType::INTEGER,
                description: 'The ID of the variant to price (from variant_search or variant_detail).',
                required: true,
            ),
            new ToolProperty(
                name: 'price',
                type: PropertyType::NUMBER,
                description: 'List price on this channel.',
            ),
            new ToolProperty(
                name: 'discounted_price',
                type: PropertyType::NUMBER,
                description: 'Sale price. When set and non-zero this is what the customer pays. Set it to 0 to end '
                    . 'a sale and go back to the list price.',
            ),
            new ToolProperty(
                name: 'is_published',
                type: PropertyType::BOOLEAN,
                description: 'Whether the variant is listed on this channel. Defaults to leaving it as it is.',
            ),
            new ToolProperty(
                name: 'channel_id',
                type: PropertyType::INTEGER,
                description: 'Channel to price on (from list_channels). Omit to use the default channel, which is '
                    . 'the one the cart reads.',
            ),
            new ToolProperty(
                name: 'warehouse_id',
                type: PropertyType::INTEGER,
                description: 'Warehouse the price belongs to — channel prices hang off a warehouse row. Omit to '
                    . 'use the company default warehouse.',
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        int $variant_id,
        ?float $price = null,
        ?float $discounted_price = null,
        ?bool $is_published = null,
        ?int $channel_id = null,
        ?int $warehouse_id = null,
    ): array {
        if ($denied = $this->requireAdminOrError()) {
            return $denied;
        }

        return $this->setCatalogVariantChannelPrice(
            variantId: $variant_id,
            price: $price,
            discountedPrice: $discounted_price,
            isPublished: $is_published,
            channelId: $channel_id,
            warehouseId: $warehouse_id,
        );
    }
}
