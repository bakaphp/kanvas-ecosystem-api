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
 * The storefront on/off switch for one variant on one channel. Split out from
 * set_variant_channel_price so "take this off the web channel" has a tool whose name says that —
 * and so activating can never invent a price to do it.
 */
#[AgentTool(name: 'Set Variant Channel Status', category: 'inventory')]
class SetVariantChannelStatusTool extends Tool implements HasRunKey
{
    use GuardsAdminForTool;
    use HasKanvasContext;
    use ManagesCatalogVariants;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'set_variant_channel_status',
            description: 'Activate or deactivate a variant on a sales channel — whether shoppers on that channel '
                . 'can see and buy it. Its price stays as it was, so deactivating and reactivating is safe. The '
                . 'variant has to already be listed on the channel; if it is not, use set_variant_channel_price to '
                . 'list it with a price first. Use variant_detail to see the channels it is on and list_channels '
                . 'for the rest. Only an administrator can do this.',
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
                description: 'The ID of the variant (from variant_search or variant_detail).',
                required: true,
            ),
            new ToolProperty(
                name: 'is_published',
                type: PropertyType::BOOLEAN,
                description: 'true to activate the variant on the channel, false to take it off.',
                required: true,
            ),
            new ToolProperty(
                name: 'channel_id',
                type: PropertyType::INTEGER,
                description: 'Channel to switch it on (from list_channels). Omit to use the default channel.',
            ),
            new ToolProperty(
                name: 'warehouse_id',
                type: PropertyType::INTEGER,
                description: 'Warehouse the listing belongs to — channel listings hang off a warehouse row. Omit '
                    . 'to use the company default warehouse.',
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        int $variant_id,
        bool $is_published,
        ?int $channel_id = null,
        ?int $warehouse_id = null,
    ): array {
        if ($denied = $this->requireAdminOrError()) {
            return $denied;
        }

        return $this->setCatalogVariantChannelStatus(
            variantId: $variant_id,
            isPublished: $is_published,
            channelId: $channel_id,
            warehouseId: $warehouse_id,
        );
    }
}
