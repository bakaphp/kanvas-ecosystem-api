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
 * Laravel-AI counterpart of the Neuron set_variant_channel_status tool.
 */
#[AgentTool(name: 'Set Variant Channel Status', category: 'inventory')]
class SetVariantChannelStatusTool implements KanvasToolInterface
{
    use GuardsAdminForTool;
    use HandlesToolRequest;
    use HasKanvasContext;
    use ManagesCatalogVariants;

    public function name(): string
    {
        return 'set_variant_channel_status';
    }

    #[Override]
    public function description(): Stringable|string
    {
        return 'Activate or deactivate a variant on a sales channel — whether shoppers on that channel can see '
            . 'and buy it. Its price stays as it was, so deactivating and reactivating is safe. The variant has to '
            . 'already be listed on the channel; if it is not, use set_variant_channel_price to list it with a '
            . 'price first. Use variant_detail to see the channels it is on and list_channels for the rest. Only '
            . 'an administrator can do this.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $denied = $this->adminDenialFor('change channel listings');

        if ($denied !== null) {
            return $denied;
        }

        return (string) json_encode(
            $this->setCatalogVariantChannelStatus(
                variantId: $request->integer('variant_id'),
                isPublished: $request->boolean('is_published'),
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
                ->description('The ID of the variant (from variant_search or variant_detail).')
                ->required(),
            'is_published' => $schema
                ->boolean()
                ->description('true to activate the variant on the channel, false to take it off.')
                ->required(),
            'channel_id' => $schema
                ->integer()
                ->description('Channel to switch it on (from list_channels). Omit to use the default channel.'),
            'warehouse_id' => $schema
                ->integer()
                ->description(
                    'Warehouse the listing belongs to — channel listings hang off a warehouse row. Omit to use '
                    . 'the company default warehouse.'
                ),
        ];
    }
}
