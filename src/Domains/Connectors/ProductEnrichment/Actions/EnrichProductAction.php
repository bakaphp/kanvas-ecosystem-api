<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ProductEnrichment\Actions;

use Illuminate\Support\Arr;
use Kanvas\Connectors\ProductEnrichment\DataTransferObject\EnrichmentConfig;
use Kanvas\Connectors\ProductEnrichment\Enums\AttributeEnum;
use Kanvas\Connectors\ProductEnrichment\Enums\CustomFieldEnum;
use Kanvas\Connectors\ProductEnrichment\Services\ProductEnrichmentAgentService;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Recommendations\Enums\ConfigurationEnum as RecommendationConfigurationEnum;
use Kanvas\Inventory\Recommendations\Enums\SemanticProfileStrategyEnum;
use Laravel\Ai\Responses\StructuredAgentResponse;

/**
 * price_tier / in_stock / price are intentionally NOT stored here — they are derived
 * at index time in Products::toSearchableArray() so they stay in sync without re-enriching.
 */
class EnrichProductAction
{
    private const int MAX_PROMPT_ATTRIBUTES = 25;

    public function __construct(
        private readonly Products $product,
        private readonly ?int $agentId = null,
    ) {
    }

    public function execute(): array
    {
        if ($this->product->company === null) {
            return ['status' => 'skipped', 'reason' => 'product has no company'];
        }

        $hash = $this->contentHash();

        // Hash gate: skip the LLM call when name/description/categories are unchanged.
        if ($this->product->get(CustomFieldEnum::ENRICHMENT_HASH->value) === $hash) {
            return ['status' => 'unchanged'];
        }

        $agent = ProductEnrichmentAgentService::resolveAgent($this->product->app, $this->agentId);
        $config = EnrichmentConfig::forAgent($agent);
        $handler = ProductEnrichmentAgentService::handlerFor($this->product->app, $agent);

        // Writes are attributed to the agent's own user (app-scoped), not a generic AI user.
        $actor = $agent->user ?? $this->product->company->getAiAgentUserOrFail();

        $response = $handler->promptWithConfig($this->productPrompt());
        $data = $response instanceof StructuredAgentResponse ? $response->structured : [];

        // @todo addAttributes() forces is_visible=true — write these is_visible=false so
        //       internal enrichment facets don't render on the PDP.
        $this->product->addAttributes($actor, [
            ['name' => AttributeEnum::AUDIENCE->value,  'value' => $config->clean('audience', Arr::wrap($data['audience'] ?? null))],
            ['name' => AttributeEnum::OCCASION->value,  'value' => $config->clean('occasion', Arr::wrap($data['occasion'] ?? null))],
            ['name' => AttributeEnum::INTERESTS->value, 'value' => $config->clean('interests', Arr::wrap($data['interests'] ?? null))],
        ]);

        // Reconcile only OUR vocab tags (remove vocab, re-add current) — syncTags() would
        // wipe a merchant's own tags.
        $this->product->removeTags($config->facets['tags']);
        $this->product->addTags($config->clean('tags', Arr::wrap($data['tags'] ?? null)));

        $blurb = trim(($data['blurb_es'] ?? '') . ' ' . ($data['blurb_en'] ?? ''));
        $this->product->set(CustomFieldEnum::BLURB->value, $blurb);

        // Only a run that produced a blurb counts as done, or an empty one is permanent.
        if ($blurb !== '') {
            $this->product->set(CustomFieldEnum::ENRICHMENT_HASH->value, $hash);
        }

        $this->product->searchable();

        return [
            'status' => 'enriched',
            'blurb' => $blurb,
        ];
    }

    /**
     * Fingerprints everything the prompt is built from — attributes included, or a
     * product whose body type changed keeps a blurb describing the old one forever.
     * The framing strategy is in here too: flipping an app from generic to gift
     * rewrites the prompt, so every blurb written under the old one is stale.
     */
    private function contentHash(): string
    {
        return md5(implode('|', [
            $this->product->name,
            $this->product->description,
            $this->product->categories->pluck('name')->implode(','),
            $this->promptAttributes(),
            SemanticProfileStrategyEnum::fromApp(
                $this->product->app?->get(RecommendationConfigurationEnum::SEMANTIC_PROFILE_STRATEGY->value),
            )->value,
        ]));
    }

    private function productPrompt(): string
    {
        $categories = $this->product->categories->pluck('name')->implode(', ');

        $prompt = "Enrich this product:\n"
            . "name: {$this->product->name}\n"
            . "description: {$this->product->description}\n"
            . "categories: {$categories}";

        $attributes = $this->promptAttributes();

        return $attributes === '' ? $prompt : $prompt . "\nattributes:\n" . $attributes;
    }

    /**
     * Attributes are where a catalog differentiates. Without them every product in
     * a vertical gets the same interchangeable blurb, which embeds to one place.
     * Enrichment's own facet outputs are excluded — feeding them back entrenches them.
     */
    private function promptAttributes(): string
    {
        $skip = array_map(
            static fn (AttributeEnum $attribute): string => mb_strtolower($attribute->value),
            AttributeEnum::cases(),
        );

        $lines = [];

        foreach ($this->product->searchableAttributes() as $attribute) {
            $name = (string) ($attribute['name'] ?? '');
            $value = $attribute['value'] ?? null;
            $value = is_array($value) ? implode(', ', array_filter($value, 'is_scalar')) : (string) $value;
            $value = trim($value);

            if ($name === '' || $value === '' || in_array(mb_strtolower($name), $skip, true)) {
                continue;
            }

            $lines[] = '  ' . $name . ': ' . mb_strimwidth($value, 0, 120, '…');

            if (count($lines) >= self::MAX_PROMPT_ATTRIBUTES) {
                break;
            }
        }

        return implode("\n", $lines);
    }
}
