<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Recommendations\Enrichment\Services;

use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Recommendations\Enrichment\Agents\ProductEnrichmentAgent;
use Kanvas\Inventory\Recommendations\Enrichment\Enums\AttributeEnum;
use Kanvas\Inventory\Recommendations\Enrichment\Enums\CustomFieldEnum;
use Kanvas\Inventory\Recommendations\Enums\ConfigurationEnum as RecommendationConfigurationEnum;
use Kanvas\Inventory\Recommendations\Enums\SemanticProfileStrategyEnum;
use Kanvas\Inventory\Recommendations\Services\ProductDiscoverySettings;

/**
 * What a product's blurb was written from, as one hash. Checked before a job is
 * queued as well as inside it: a price or stock edit, a re-save, or a re-run of
 * the backfill changes nothing the prompt reads, so it must not cost a queued
 * job, let alone an LLM call.
 */
class ProductEnrichmentFingerprintService
{
    private const int MAX_PROMPT_ATTRIBUTES = 25;

    /**
     * Fingerprints everything the prompt is built from — attributes included, or a
     * product whose body type changed keeps a blurb describing the old one forever.
     * The framing strategy is in here too: flipping an app from generic to gift
     * rewrites the prompt, so every blurb written under the old one is stale. The
     * description is hashed raw, so changing the prompt's length cap never
     * re-enriches a catalog.
     */
    public static function for(Products $product): string
    {
        $parts = [
            $product->name,
            $product->description,
            $product->categories->pluck('name')->implode(','),
            self::attributeLines($product),
            SemanticProfileStrategyEnum::fromApp(
                $product->app?->get(RecommendationConfigurationEnum::SEMANTIC_PROFILE_STRATEGY->value),
            )->value,
        ];

        // Changing the blurb languages rewrites every blurb. The default stays out of the hash so
        // catalogs already enriched in es+en keep a current fingerprint.
        $languages = ProductEnrichmentAgent::blurbLanguages($product->app, $product->company);

        if ($languages !== ProductDiscoverySettings::DEFAULT_QUERY_LANGUAGES) {
            $parts[] = implode(',', $languages);
        }

        return md5(implode('|', $parts));
    }

    public static function isCurrent(Products $product): bool
    {
        return $product->get(CustomFieldEnum::ENRICHMENT_HASH->value) === self::for($product);
    }

    /**
     * Attributes are where a catalog differentiates. Without them every product in
     * a vertical gets the same interchangeable blurb, which embeds to one place.
     * Enrichment's own facet outputs are excluded — feeding them back entrenches them.
     */
    public static function attributeLines(Products $product): string
    {
        $skip = AttributeEnum::names();
        $lines = [];

        foreach ($product->searchableAttributes() as $attribute) {
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
