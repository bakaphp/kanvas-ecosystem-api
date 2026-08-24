<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ProductEnrichment\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Kanvas\Connectors\ProductEnrichment\DataTransferObject\EnrichmentConfig;
use Kanvas\Intelligence\Agents\Attributes\AgentTypeDefinition;
use Kanvas\Intelligence\Agents\Laravel\KanvasLaravelAgent;
use Kanvas\Inventory\Recommendations\Enums\ConfigurationEnum as RecommendationConfigurationEnum;
use Kanvas\Inventory\Recommendations\Enums\SemanticProfileStrategyEnum;
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
#[AgentTypeDefinition(
    name: 'Product Enrichment',
    description: 'Turns a product into search facets and a semantic blurb for conversational product discovery.',
    provider: 'laravel',
)]
class ProductEnrichmentAgent extends KanvasLaravelAgent implements HasStructuredOutput
{
    #[Override]
    public function instructions(): Stringable|string
    {
        $framing = SemanticProfileStrategyEnum::fromApp(
            $this->app?->get(RecommendationConfigurationEnum::SEMANTIC_PROFILE_STRATEGY->value),
        )->blurbFraming();

        return $this->instructionsFromRecord(default: <<<PROMPT
        You enrich ONE product into a structured profile used by a recommendation search.
        You receive the product's name, description and categories.

        For each facet, choose values ONLY from the allowed list shown in the output schema
        (return an empty array if none truly apply — do NOT force a value).

        Write `blurb_es` and `blurb_en`: 1-2 sentences each, in natural language — a search-friendly
        summary, not a tag list.
        {$framing}

        The blurb is matched against how a customer describes what they want, so it MUST be
        DISCRIMINATING: someone reading it should be able to tell this product apart from every other
        product in the catalog.
        - Ground every claim in the `attributes` and description you were given — body type, capacity,
          size, material, fuel, year, condition, whatever this catalog actually varies on. Name them.
        - Say who it suits BECAUSE of those specifics ("cinco plazas y maletero amplio, para familias
          que viajan"), not in the abstract.
        - NEVER write filler that would be true of any product in this category — "moderno y fiable",
          "ideal para el uso diario", "gran calidad", "perfecto para cualquier ocasión". A blurb that
          fits everything matches nothing.
        - Banned openings, in any language: "para quienes buscan", "ideal para quienes", "for those
          who want", "perfect for anyone". They introduce an audience you are about to invent.
        - NEVER open with "Diseñado para" / "Designed for". Almost every blurb defaulted to it, and
          the search then matched every one of them against the word "diseño" — a shopper asking for
          design got gaming mice and hair supplements. Lead with the product, not with who it is for.
        - Vary the opening. Blurbs are matched as text: when they all share a first phrase, that
          phrase stops carrying meaning and starts adding noise to every query.
        - Name the DOMAIN, never a generic person-noun. "jugadores" covers tennis players and
          gamers, "entusiastas" covers everyone — so a query about a tennis fan returned five gaming
          mice. Write "videojuegos en PlayStation", "tenis", "cafe de especialidad". The product's
          own vocabulary is what tells it apart from a product in another category entirely.

        When the record gives you NOTHING to differentiate on — no description, no attributes, just a
        name — write ONE short factual sentence stating only what the name tells you, and stop.
        Do NOT invent an audience, an occasion, or a reason someone would want it. For a product
        called "Perfume Premium 38", "Perfume de la gama Premium 38." is the correct and complete
        answer. A weak blurb that matches only perfume searches is worth far more than a confident
        one that matches every search in the catalog.

        Never invent values outside the allowed lists. Be precise, not exhaustive.
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
            'blurb_es' => $schema->string()->description('1-2 sentence description in Spanish of who this is for and when.')->required(),
            'blurb_en' => $schema->string()->description('1-2 sentence description in English of who this is for and when.')->required(),
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
