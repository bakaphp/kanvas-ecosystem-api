<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ProductEnrichment\Actions;

use Illuminate\Support\Arr;
use Kanvas\Connectors\ProductEnrichment\DataTransferObject\EnrichmentConfig;
use Kanvas\Connectors\ProductEnrichment\Enums\AttributeEnum;
use Kanvas\Connectors\ProductEnrichment\Enums\CustomFieldEnum;
use Kanvas\Connectors\ProductEnrichment\Services\ProductEnrichmentAgentService;
use Kanvas\Inventory\Products\Models\Products;
use Laravel\Ai\Responses\StructuredAgentResponse;

/**
 * price_tier / in_stock / price are intentionally NOT stored here — they are derived
 * at index time in Products::toSearchableArray() so they stay in sync without re-enriching.
 */
class EnrichProductAction
{
    public function __construct(
        private readonly Products $product,
        private readonly ?int $agentId = null,
    ) {
    }

    public function execute(): array
    {
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
        $this->product->set(CustomFieldEnum::ENRICHMENT_HASH->value, $hash);

        $this->product->searchable();

        return [
            'status' => 'enriched',
            'blurb' => $blurb,
        ];
    }

    private function contentHash(): string
    {
        $categories = (string) $this->product->categories->pluck('name')->implode(',');

        return md5(implode('|', [
            $this->product->name,
            $this->product->description,
            $categories,
        ]));
    }

    private function productPrompt(): string
    {
        $categories = $this->product->categories->pluck('name')->implode(', ');

        return "Enrich this product:\n"
            . "name: {$this->product->name}\n"
            . "description: {$this->product->description}\n"
            . "categories: {$categories}";
    }
}
