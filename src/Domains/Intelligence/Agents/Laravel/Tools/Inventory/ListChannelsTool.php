<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Tools\Inventory;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Laravel\Contracts\KanvasToolInterface;
use Kanvas\Intelligence\Agents\Laravel\Traits\HandlesToolRequest;
use Kanvas\Intelligence\Agents\Laravel\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Traits\ListsCatalogReferenceData;
use Laravel\Ai\Tools\Request;
use Override;
use Stringable;

/**
 * Laravel-AI counterpart of the Neuron list_channels tool.
 */
#[AgentTool(name: 'List Channels', category: 'inventory')]
class ListChannelsTool implements KanvasToolInterface
{
    use HandlesToolRequest;
    use HasKanvasContext;
    use ListsCatalogReferenceData;

    public function name(): string
    {
        return 'list_channels';
    }

    #[Override]
    public function description(): Stringable|string
    {
        return 'List the company\'s sales channels, default first. Selling prices are held per channel, so use '
            . 'this to get a channel_id before calling set_variant_channel_price. The cart reads the price from '
            . 'the default channel, and only while that channel is published.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        return (string) json_encode(
            $this->listCatalogChannels($this->nullableInt($request, 'limit')),
            JSON_PRETTY_PRINT
        );
    }

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema
                ->integer()
                ->description('How many channels to return. Defaults to 25, maximum 100.'),
        ];
    }
}
