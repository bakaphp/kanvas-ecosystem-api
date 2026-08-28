<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Inventory;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Traits\ListsCatalogReferenceData;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * Makes the channel_id set_variant_channel_price accepts discoverable, and surfaces which channel is
 * the default published one — the only channel the cart reads a price from.
 */
#[AgentTool(name: 'List Channels', category: 'inventory')]
class ListChannelsTool extends Tool
{
    use HasKanvasContext;
    use ListsCatalogReferenceData;

    public function __construct()
    {
        parent::__construct(
            name: 'list_channels',
            description: 'List the company\'s sales channels, default first. Selling prices are held per channel, '
                . 'so use this to get a channel_id before calling set_variant_channel_price. The cart reads the '
                . 'price from the default channel, and only while that channel is published.',
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
                name: 'limit',
                type: PropertyType::INTEGER,
                description: 'How many channels to return. Defaults to 25, maximum 100.',
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?int $limit = null): array
    {
        return $this->listCatalogChannels($limit);
    }
}
