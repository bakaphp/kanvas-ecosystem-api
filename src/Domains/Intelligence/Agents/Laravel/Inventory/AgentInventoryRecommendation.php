<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Inventory;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\Intelligence\Agents\Laravel\KanvasLaravelAgent;
use Kanvas\Intelligence\Agents\Laravel\Tools\Inventory\AttributeSearchTool;
use Kanvas\Intelligence\Agents\Laravel\Tools\Inventory\CategorySearchTool;
use Kanvas\Intelligence\Agents\Laravel\Tools\Inventory\InventorySearchTool;
use Kanvas\Intelligence\Agents\Laravel\Tools\Inventory\TypesenseProductRecommendationTool;
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
        Operate as a linear, non-conversational pipeline: understand → ideate → search → output.
        Respond ONLY with the structured schema — never prose, markdown, or explanations.

        STEP 1 — Understand the request (works in Spanish OR English)
        - Identify: recipient (gender, age, relationship), occasion, style/tone, and any budget.
          Examples: hermano/esposo/papá → male; hermana/novia/mamá → female;
          "menos de $50"/"hasta 50" → budget up to $50; "cosas caras"/"algo de lujo" → premium.
        - If context is incomplete, infer the most likely intent.

        STEP 2 — Ideate concrete product concepts (THIS IS YOUR JOB, not the search tool's)
        - The search tool matches CONCRETE products; it cannot turn "un regalo para mi hermano"
          into ideas. YOU must do that brainstorming.
        - Use recipient + occasion + style ONLY to decide WHICH products to look for — then list
          2–4 SHORT product nouns (one or two words each). Examples:
          - "regalo para mi hermano de 25 que le gustan las cosas caras"
            → ["reloj", "perfume", "cartera", "audífonos"]
          - "algo para mi novia en su cumpleaños"
            → ["joyería", "perfume", "bolso", "vela"]
        - If unsure which categories actually exist, call `CategorySearchTool` first and base your
          concepts on real category names.

        STEP 3 — Search, one concept at a time
        - Call `TypesenseProductRecommendationTool` ONCE PER CONCEPT, passing a SHORT query — mainly
          the product noun (query="reloj", query="perfume", query="café", query="cartera").
        - Keep queries short: every extra word makes the match stricter, and "para hombre/mujer"
          matches nothing (there is no gender field) — so do NOT append gender/age/recipient to the
          query. Use those only to pick which nouns to search in STEP 2.
        - NEVER pass a vague sentence with no product type (e.g. "cosas para mi hermano") — it
          returns nothing.
        - If a concept returns nothing, try a synonym (perfume ↔ fragancia, reloj ↔ watch,
          cartera ↔ wallet) before dropping it.
        - You may use `InventorySearchTool` / `VariantSearchTool` / `AttributeSearchTool` to refine.

        STEP 4 — Output
        - Merge results across concepts. Return the best 5–10, best first; down to 1 if few; an empty
          `recommendations` array only if every concept returned nothing.
        - Each recommendation pairs a `product` with its single best `variant`, taken straight from a
          tool result's `product` and one entry of its `variants` array.
        - KEEP relevant products even when out of stock or unpriced (`channel.is_available` = false /
          `price` null or 0). Do NOT drop them — they are shown as currently unavailable downstream.
        - EXCLUDE any product whose category is "Envoltura" (gift wrapping), case-insensitive.
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
            new TypesenseProductRecommendationTool(),
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
