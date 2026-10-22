<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Recommendations\Enrichment\Agents;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Attributes\AgentTypeDefinition;
use Kanvas\Intelligence\Agents\Laravel\KanvasLaravelAgent;
use Kanvas\Intelligence\Agents\Models\AgentLlmConfig;
use Kanvas\Intelligence\Agents\Services\AgentProviderService;
use Kanvas\Inventory\Recommendations\Enrichment\DataTransferObject\EnrichmentConfig;
use Kanvas\Inventory\Recommendations\Enums\ConfigurationEnum as RecommendationConfigurationEnum;
use Kanvas\Inventory\Recommendations\Enums\SemanticProfileStrategyEnum;
use Kanvas\Inventory\Recommendations\Services\ProductDiscoverySettings;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Override;
use Stringable;

/**
 * A one-shot structured-output agent (no tools, no chat): given a product, it emits facets
 * constrained to the app's controlled vocabulary plus a search blurb. Registered as an AgentType
 * handler so an app can override the prompt (`instructions`), the vocabulary
 * (`config.enrichment.facets`) or the model from the agents UI.
 */
#[AgentTypeDefinition(
    name: 'Product Enrichment',
    description: 'Turns a product into search facets and a semantic blurb for conversational product discovery.',
    provider: 'laravel',
)]
class ProductEnrichmentAgent extends KanvasLaravelAgent implements HasProviderOptions, HasStructuredOutput
{
    public const string DEFAULT_MODEL = 'gemini-2.5-flash-lite';

    private ?AgentLlmConfig $tenantLlmConfig = null;
    private bool $tenantLlmConfigResolved = false;

    public function setConfigurationWithoutAgent(Apps $app, Companies $company): void
    {
        $this->app = $app;
        $this->company = $company;
    }

    /**
     * Not a real agent: one structured call. An Agent row only exists to edit the prompt or pick a
     * provider from the agents UI, and wins when present. Without one, the company (then app) setting
     * `product_enrichment_llm_config_id` may name any of the tenant's LLM configs — DeepSeek, Mistral,
     * an OpenAI-compatible endpoint — so a cheaper model needs no Agent row. Provider, model and key
     * then flow through KanvasLaravelAgent exactly as for a configured agent.
     */
    #[Override]
    protected function selectedLlmConfig(): ?AgentLlmConfig
    {
        if ($this->agentRecord !== null) {
            return parent::selectedLlmConfig();
        }

        if (! $this->tenantLlmConfigResolved) {
            $this->tenantLlmConfigResolved = true;
            $configId = (int) ($this->company?->get(RecommendationConfigurationEnum::ENRICHMENT_LLM_CONFIG->value)
                ?? $this->app?->get(RecommendationConfigurationEnum::ENRICHMENT_LLM_CONFIG->value)
                ?? 0);

            $this->tenantLlmConfig = $configId > 0 && $this->app !== null && $this->company !== null
                ? AgentProviderService::tenantConfig($this->app, $this->company, $configId)
                : null;
        }

        return $this->tenantLlmConfig;
    }

    #[Override]
    protected function getProvider(): ?Lab
    {
        return parent::getProvider() ?? Lab::Gemini;
    }

    #[Override]
    protected function getModel(): ?string
    {
        return parent::getModel()
            ?? Str::trimToNull((string) $this->app?->get(RecommendationConfigurationEnum::ENRICHMENT_MODEL->value))
            ?? self::DEFAULT_MODEL;
    }

    /**
     * laravel/ai talks to Gemini through the Interactions API, which has no `thinkingBudget`: a
     * budget of 0 is rejected as an unknown parameter. The only switch it exposes is
     * `thinking_level`, and what a model accepts differs. Thought tokens bill as output, so on
     * gemini-2.5-flash they are most of the cost (measured: 1,904 thought tokens for a 3-token answer).
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function providerOptions(Lab|string $provider): array
    {
        if ($provider !== Lab::Gemini && $provider !== Lab::Gemini->value) {
            return [];
        }

        $level = $this->thinkingLevelFor((string) $this->getModel());

        return $level === null ? [] : ['generation_config' => ['thinking_level' => $level]];
    }

    /**
     * Flash-Lite does not think and answers 400 to any level; Gemini 3 models take `minimal`; the
     * 2.5 models reject `minimal`, and `low` is the cheapest level they accept.
     */
    private function thinkingLevelFor(string $model): ?string
    {
        return match (true) {
            str_contains($model, 'flash-lite') => null,
            str_starts_with($model, 'gemini-3') => 'minimal',
            default => 'low',
        };
    }

    #[Override]
    public function instructions(): Stringable|string
    {
        $framing = SemanticProfileStrategyEnum::fromApp(
            $this->app?->get(RecommendationConfigurationEnum::SEMANTIC_PROFILE_STRATEGY->value),
        )->blurbFraming();
        $blurbFields = implode(', ', array_map(
            static fn (string $language): string => '`' . self::blurbField($language) . '`',
            self::blurbLanguages($this->app),
        ));

        return $this->instructionsFromRecord(default: <<<PROMPT
        You enrich ONE product into a structured profile used by a recommendation search.
        You receive the product's name, description and categories.

        For each facet, choose values ONLY from the allowed list shown in the output schema
        (return an empty array if none truly apply — do NOT force a value).

        Write {$blurbFields}: 1-2 sentences each, each in its own language — a search-friendly
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

        When the record does not identify a real product at all — a placeholder or test name ("Test
        product", "Lorem ipsum", "Product 1"), a description asking someone to fill in details, or no
        usable name — set `insufficient_information` to true and leave every blurb EMPTY. Never write a
        blurb about the record being incomplete: that text would match unrelated searches.

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

        $fields = [
            'audience' => $this->facetField($schema, $config, 'audience')->required(),
            'occasion' => $this->facetField($schema, $config, 'occasion'),
            'interests' => $this->facetField($schema, $config, 'interests'),
            'tags' => $this->facetField($schema, $config, 'tags'),
            'insufficient_information' => $schema->boolean()
                ->description('true when the record does not identify a real product (placeholder, test data, no usable name); leave every blurb empty then.')
                ->required(),
        ];

        foreach (self::blurbLanguages($this->app) as $language) {
            $fields[self::blurbField($language)] = $schema->string()
                ->description("1-2 sentence search blurb written in the language with ISO 639-1 code '{$language}'.")
                ->required();
        }

        return $fields;
    }

    /**
     * Only the catalog's languages: each extra one is paid output tokens and text no shopper searches in.
     *
     * @return list<string>
     */
    public static function blurbLanguages(?AppInterface $app): array
    {
        return $app !== null ? ProductDiscoverySettings::queryLanguages($app) : ProductDiscoverySettings::DEFAULT_QUERY_LANGUAGES;
    }

    public static function blurbField(string $language): string
    {
        return 'blurb_' . $language;
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
