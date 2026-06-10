<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ProductEnrichment\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Kanvas\Connectors\ProductEnrichment\DataTransferObject\EnrichmentConfig;
use Kanvas\Intelligence\Agents\Laravel\KanvasLaravelAgent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Override;
use Stringable;

/**
 * The per-app enrichment "brain". A one-shot structured-output agent (no tools,
 * no chat): given a product, it emits a gift profile constrained to the app's
 * controlled vocabulary. The per-app prompt is the agent's `instructions`
 * (default→override); the vocabulary is the agent's `config.enrichment.facets`.
 *
 * Registered as an AgentType handler so each app can override instructions/model
 * in the existing agent UI; the connector resolves + invokes it per product.
 */
class ProductEnrichmentAgent extends KanvasLaravelAgent implements HasStructuredOutput
{
    #[Override]
    public function instructions(): Stringable|string
    {
        return $this->instructionsFromRecord(default: <<<'PROMPT'
        You enrich ONE product into a structured profile used by a recommendation search.
        You receive the product's name, description and categories.

        For each facet, choose values ONLY from the allowed list shown in the output schema
        (return an empty array if none truly apply — do NOT force a value).
        Write `blurb_es` and `blurb_en`: 1-2 sentences each, in natural language, describing who the
        product is for, the occasion/use, and its style — a short, search-friendly summary, not a tag list.

        Never invent values outside the allowed lists. Be precise, not exhaustive.
        The store's domain and tone are set per app via this agent's instructions; with no override,
        assume a general retail catalog.
        PROMPT);
    }

    /**
     * @return iterable<object>
     */
    #[Override]
    public function agentTools(): iterable
    {
        return [];
    }

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        $config = EnrichmentConfig::forAgent($this->agentRecord);

        return [
            'audience' => $this->facetField($schema, $config, 'audience')->required(),
            'occasion' => $this->facetField($schema, $config, 'occasion'),
            'interests' => $this->facetField($schema, $config, 'interests'),
            'tags' => $this->facetField($schema, $config, 'tags'),
            'gift_blurb_es' => $schema->string()->description('1-2 sentence gift description in Spanish.')->required(),
            'gift_blurb_en' => $schema->string()->description('1-2 sentence gift description in English.')->required(),
        ];
    }

    /**
     * A string[] facet whose description carries this app's allowed vocabulary.
     * (Values are also validated against the vocab in EnrichProductAction, so a
     * stray value can never reach the index.)
     */
    private function facetField(JsonSchema $schema, EnrichmentConfig $config, string $facet): Type
    {
        $allowed = implode(', ', $config->facets[$facet] ?? []);

        return $schema
            ->array()
            ->items($schema->string())
            ->description("Choose ONLY from: {$allowed}. Empty array if none apply.");
    }
}
