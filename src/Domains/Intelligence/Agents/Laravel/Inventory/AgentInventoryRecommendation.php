<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Inventory;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\Intelligence\Agents\Laravel\KanvasLaravelAgent;
use Kanvas\Intelligence\Agents\Laravel\Tools\Inventory\AttributeSearchTool;
use Kanvas\Intelligence\Agents\Laravel\Tools\Inventory\CategorySearchTool;
use Kanvas\Intelligence\Agents\Laravel\Tools\Inventory\InventorySearchTool;
use Kanvas\Intelligence\Agents\Laravel\Tools\Inventory\ProductRecommendationLookupTool;
use Kanvas\Intelligence\Agents\Laravel\Tools\Inventory\VariantSearchTool;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\Tool;
use Override;
use Stringable;

class AgentInventoryRecommendation extends KanvasLaravelAgent implements HasStructuredOutput
{
    #[Override]
    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
        You are an inventory product-recommendation engine.

        The user describes who the gift / recommendation is for, their interests, budget, and any other context.
        Your job: pick the best matching products from the inventory and return them in the structured schema.

        Workflow:
        1. Extract from the request:
           - List of distinct interests / categories / keywords (e.g. "cocina", "ropa", "perfume").
           - min_price and max_price if a budget is mentioned.
        2. Call `ProductRecommendationLookupTool` ONCE PER INTEREST, passing the interest as `keyword` and the budget as `min_price`/`max_price`.
        3. If no results come back for an interest, try a synonym (e.g. "perfume" → "fragrance", "cocina" → "kitchen") before giving up on it.
        4. Optionally use `CategorySearchTool`, `AttributeSearchTool`, or `VariantSearchTool` to refine before calling the lookup tool again.
        5. Pick the best 1 variant per recommended product (most relevant, in-stock, within budget).
        6. Return between 1 and 6 recommendations total, distributed across the interests when possible.
        7. NEVER invent ids, slugs, prices, files, or attributes — pass them through from the tool output exactly.

        Output rules:
        - You MUST respond using the structured schema. No prose.
        - `product` and `variant` come from the tool's `product` and one entry of its `variants` array.
        - If the lookup tool returns nothing for any interest, return an empty `recommendations` array.
        INSTRUCTIONS;
    }

    /**
     * @return Tool[]
     */
    #[Override]
    public function tools(): iterable
    {
        return [
            new ProductRecommendationLookupTool(),
            new InventorySearchTool(),
            new VariantSearchTool(),
            new CategorySearchTool(),
            new AttributeSearchTool(),
        ];
    }

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        $fileItem = $schema->object([
            'id' => $schema->integer()->required(),
            'url' => $schema->string()->required(),
            'name' => $schema->string(),
        ]);

        $categoryItem = $schema->object([
            'id' => $schema->integer()->required(),
            'name' => $schema->string()->required(),
        ]);

        $attributeItem = $schema->object([
            'id' => $schema->integer(),
            'name' => $schema->string()->required(),
            'value' => $schema->string()->required(),
        ]);

        $product = $schema->object([
            'id' => $schema->integer()->required(),
            'slug' => $schema->string()->required(),
            'name' => $schema->string()->required(),
            'files' => $schema->array()->items($fileItem),
            'categories' => $schema->array()->items($categoryItem),
        ]);

        $channel = $schema->object([
            'price' => $schema->number(),
            'discounted_price' => $schema->number(),
            'is_on_sale' => $schema->boolean(),
            'quantity' => $schema->integer(),
        ]);

        $variant = $schema->object([
            'id' => $schema->integer()->required(),
            'slug' => $schema->string(),
            'name' => $schema->string()->required(),
            'sku' => $schema->string(),
            'description' => $schema->string(),
            'attributes' => $schema->array()->items($attributeItem),
            'channel' => $channel,
            'files' => $schema->array()->items($fileItem),
        ]);

        $recommendation = $schema->object([
            'product' => $product->required(),
            'variant' => $variant->required(),
        ]);

        return [
            'recommendations' => $schema
                ->array()
                ->description('Recommended products. Each entry pairs a product with its chosen variant.')
                ->items($recommendation)
                ->required(),
        ];
    }
}
