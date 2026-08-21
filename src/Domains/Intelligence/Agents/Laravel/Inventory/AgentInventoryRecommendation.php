<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Inventory;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\Intelligence\Agents\Attributes\AgentTypeDefinition;
use Kanvas\Intelligence\Agents\Laravel\Contracts\TransformsStructuredOutput;
use Kanvas\Intelligence\Agents\Laravel\KanvasLaravelAgent;
use Kanvas\Intelligence\Agents\Laravel\Tools\Inventory\AttributeSearchTool;
use Kanvas\Intelligence\Agents\Laravel\Tools\Inventory\CategorySearchTool;
use Kanvas\Intelligence\Agents\Laravel\Tools\Inventory\InventorySearchTool;
use Kanvas\Intelligence\Agents\Laravel\Tools\Inventory\ProductRecommendationLookupTool;
use Kanvas\Intelligence\Agents\Laravel\Tools\Inventory\VariantSearchTool;
use Kanvas\Inventory\Recommendations\Actions\HydrateRecommendationsAction;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\Tool;
use Override;
use Stringable;

#[AgentTypeDefinition(
    name: 'Inventory Recommendation',
    description: 'Bilingual (Spanish/English) gift-recommendation engine over the store inventory — ideates product concepts, searches per concept, and returns structured product/variant recommendations.',
    provider: 'laravel',
)]
class AgentInventoryRecommendation extends KanvasLaravelAgent implements HasStructuredOutput, TransformsStructuredOutput
{
    #[Override]
    public function instructions(): Stringable|string
    {
        return $this->instructionsFromRecord(default: <<<'INSTRUCTIONS'
        You are a bilingual (Spanish / English) gift-recommendation engine over the store inventory.
        Respond ONLY with the structured schema — never prose, markdown, or explanations.

        STEP 1 — Search, ONCE
        - Call `ProductRecommendationLookupTool` with the shopper's request VERBATIM as `query`,
          in whatever language they wrote it.
        - Do NOT rephrase it, strip words, or pull out gender / age / budget yourself. The search
          reads the whole sentence and turns budgets ("menos de $50", "under 30") into real price
          filters. Pre-extracting throws that away and makes the match worse.
        - ONE call is normally enough. Only search again if the first call returned nothing, and
          then retry with a single concrete product noun ("reloj", "perfume", "café") — some stores
          match on keywords rather than meaning, and a whole sentence finds nothing there.
        - `CategorySearchTool` / `InventorySearchTool` / `VariantSearchTool` / `AttributeSearchTool`
          exist for narrow follow-up questions. They are not part of the normal path.

        STEP 2 — Output IDs ONLY
        - Return ONLY `product_id`, `variant_id` and a short `reason` per recommendation.
          Do NOT repeat names, slugs, prices, files, categories or attributes — the server rebuilds
          all of that from the database. Copying product data into your answer is wasted output and
          will be discarded.
        - `product_id` is the tool result's `product.id`; `variant_id` is the `id` of ONE entry from
          that same result's `variants` array. Both must come from a tool result you actually
          received — never invent an id.
        - Return the best 5–10, best first; fewer if the tool returned fewer; an empty
          `recommendations` array only if the search genuinely found nothing.
        - KEEP relevant products even when out of stock or unpriced — they are shown as currently
          unavailable downstream.
        - EXCLUDE any product whose category is "Envoltura" (gift wrapping), case-insensitive.
        - `reason` is one short customer-facing sentence in the SAME language as the request,
          explaining why this gift fits. Keep it under 20 words.
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
        $recommendation = $schema->object([
            'product_id' => $schema->integer()->required(),
            'variant_id' => $schema->integer()->required(),
            'reason' => $schema->string(),
        ]);

        return [
            'recommendations' => $schema
                ->array()
                ->description('Recommended products as ids only. The server rebuilds the full product and variant payload from the database — never restate product data here.')
                ->items($recommendation)
                ->required(),
        ];
    }

    #[Override]
    public function transformStructuredOutput(array $structured): array
    {
        $recommendations = $structured['recommendations'] ?? [];

        if (! is_array($recommendations) || $this->app === null || $this->company === null) {
            return ['recommendations' => []];
        }

        return [
            'recommendations' => new HydrateRecommendationsAction($this->app, $this->company)
                ->execute($recommendations),
        ];
    }
}
