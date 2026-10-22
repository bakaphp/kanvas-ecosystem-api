<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Recommendations\Enrichment\Actions;

use Baka\Support\Str;
use Illuminate\Support\Arr;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Recommendations\Enrichment\DataTransferObject\EnrichmentConfig;
use Kanvas\Inventory\Recommendations\Enrichment\Enums\AttributeEnum;
use Kanvas\Inventory\Recommendations\Enrichment\Enums\CustomFieldEnum;
use Kanvas\Inventory\Recommendations\Enrichment\Services\ProductEnrichmentAgentService;
use Kanvas\Inventory\Recommendations\Enrichment\Services\ProductEnrichmentFingerprintService;
use Kanvas\Inventory\Recommendations\Enums\SearchFieldEnum;
use Laravel\Ai\Responses\StructuredAgentResponse;

/**
 * Price and stock are intentionally NOT stored here — the index derives them in
 * Products::toSearchableArray() so they stay in sync without re-enriching.
 */
class EnrichProductAction
{
    private const int MAX_PROMPT_DESCRIPTION_CHARS = 1500;

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

        $hash = ProductEnrichmentFingerprintService::for($this->product);

        // Hash gate: skip the LLM call when name/description/categories are unchanged.
        if ($this->product->get(CustomFieldEnum::ENRICHMENT_HASH->value) === $hash) {
            return ['status' => 'unchanged'];
        }

        $agent = ProductEnrichmentAgentService::resolveAgent($this->product->app, $this->agentId);
        $config = EnrichmentConfig::forAgent($agent);
        $handler = ProductEnrichmentAgentService::handlerFor($this->product->app, $agent, $this->product->company);

        // Only creates a missing attribute definition, so enrichment must not require AI agent setup.
        $actor = $agent?->user ?? $this->product->company->getAiAgentUser() ?? $this->product->user;

        if ($actor === null) {
            return ['status' => 'skipped', 'reason' => 'no user to attribute the enrichment to'];
        }

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
        $this->product->set(SearchFieldEnum::BLURB->value, $blurb);

        // Only a run that produced a blurb counts as done, or an empty one is permanent.
        if ($blurb !== '') {
            $this->product->set(CustomFieldEnum::ENRICHMENT_HASH->value, $hash);
        }

        $this->product->searchable();

        return [
            'status' => 'enriched',
            'blurb' => $blurb,
            'usage' => [
                'input_tokens' => $response->usage->inputTokens,
                'output_tokens' => $response->usage->outputTokens,
                'model' => $response->meta->model,
            ],
        ];
    }

    private function productPrompt(): string
    {
        $categories = $this->product->categories->pluck('name')->implode(', ');

        $prompt = "Enrich this product:\n"
            . "name: {$this->product->name}\n"
            . 'description: ' . $this->promptDescription() . "\n"
            . "categories: {$categories}";

        $attributes = ProductEnrichmentFingerprintService::attributeLines($this->product);

        return $attributes === '' ? $prompt : $prompt . "\nattributes:\n" . $attributes;
    }

    /**
     * The hash stays on the raw description (ProductEnrichmentFingerprintService) so changing the cap never re-enriches
     * a catalog; only what the model reads is trimmed.
     */
    private function promptDescription(): string
    {
        return mb_strimwidth(
            Str::htmlToText($this->product->description),
            0,
            self::MAX_PROMPT_DESCRIPTION_CHARS,
            '…',
        );
    }
}
