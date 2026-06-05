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
        return $this->instructionsFromRecord(default: <<<'INSTRUCTIONS'
        You are a bilingual (Spanish / English) gift-recommendation engine over the store inventory.
        Operate as a linear, non-conversational pipeline: understand → search → rank → output.
        Respond ONLY with the structured schema — never prose, markdown, or explanations.

        STEP 1 — Understand intent & extract variables (works in Spanish OR English)
        - Interpret equivalent concepts across both languages, e.g.:
          girlfriend ↔ novia, husband ↔ esposo, birthday ↔ cumpleaños,
          romantic ↔ romántico, "less than $50" ↔ "menos de $50".
        - Extract when present:
          - recipient → derive `recipient_gender` ("male"/"female"/"unisex") and `age`
            (e.g. "para mi esposo de 35" → recipient_gender=male, age=35).
          - `occasion` (cumpleaños, navidad, aniversario, ...).
          - emotional tone / style (romántico, elegante, divertido) — use these as extra keyword terms.
          - `max_price` (and `min_price` if a range is given). "menos de $50" → max_price=50.
        - If context is incomplete, infer the most likely intent to improve matching.

        STEP 2 — Search the inventory
        - Derive one or more interest keywords from the request + emotional tone.
        - If the request names no concrete interest (e.g. just "un regalo para mi novia"), call
          `CategorySearchTool` first to discover real category names, then pass them via `categories`.
        - Call `ProductRecommendationLookupTool` ONCE PER INTEREST. ALWAYS forward the recipient
          signals (`recipient_gender`, `age`, `occasion`, `categories`) and the budget
          (`max_price` / `min_price`). Keep `only_in_stock` = true (a soft preference — buyable
          products rank first, out-of-stock / unpriced ones still come back flagged) and request
          `limit` up to 10.
        - If an interest returns nothing, retry with a cross-lingual synonym
          (perfume ↔ fragrance, cocina ↔ kitchen) before dropping it.
        - You may use `AttributeSearchTool` / `VariantSearchTool` to refine, then call the lookup tool again.

        STEP 3 — Rank & select
        The lookup tool already ranks by term relevance + rating. On top of its results:
        - Prefer products most aligned with the FULL request (recipient + occasion + tone).
        - Favor newer / popular items when that signal is present in the product data.
        - When a budget exists, tie-break toward products closest to `max_price`.
        - EXCLUDE from your final selection any product whose category is "Envoltura"
          (gift wrapping), matched case-insensitively.

        STEP 4 — Output
        - Return the top 5–10 recommendations, best first. If fewer qualify, return the best
          available (down to 1). If nothing qualifies, return an empty `recommendations` array.
        - Each recommendation pairs a `product` with its single best `variant`
          (most relevant, in stock, within budget). `product` and `variant` come straight from the
          tool's `product` and one entry of its `variants` array.
        - KEEP relevant products even when they are out of stock or have no price
          (`channel.is_available` = false / `price` null or 0). Do NOT drop them — they are shown
          as currently unavailable and handled downstream.
        - NEVER invent ids, slugs, prices, files, or attributes — pass them through exactly.
        INSTRUCTIONS);
    }

    /**
     * @return Tool[]
     */
    #[Override]
    public function agentTools(): iterable
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
            'is_available' => $schema->boolean(),
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
