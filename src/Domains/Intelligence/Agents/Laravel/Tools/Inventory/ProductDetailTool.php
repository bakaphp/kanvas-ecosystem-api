<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Tools\Inventory;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Laravel\Contracts\KanvasToolInterface;
use Kanvas\Intelligence\Agents\Laravel\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Traits\ManagesCatalogProducts;
use Laravel\Ai\Tools\Request;
use Override;
use Stringable;

/**
 * Laravel-AI counterpart of the Neuron product_detail tool.
 */
#[AgentTool(name: 'Product Detail', category: 'inventory')]
class ProductDetailTool implements KanvasToolInterface
{
    use HasKanvasContext;
    use ManagesCatalogProducts;

    public function name(): string
    {
        return 'product_detail';
    }

    #[Override]
    public function description(): Stringable|string
    {
        return 'Get the full record of one product: description, product type, categories, attributes, '
            . 'media, and every variant with its stock per warehouse and its selling price per channel. Use this after '
            . 'inventory_search or list_available_products when you need the whole picture, and before editing a '
            . 'product so you know what it already has.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        return (string) json_encode(
            $this->detailCatalogProduct($request->integer('product_id')),
            JSON_PRETTY_PRINT
        );
    }

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'product_id' => $schema
                ->integer()
                ->description('The numeric ID of the product to fetch.')
                ->required(),
        ];
    }
}
