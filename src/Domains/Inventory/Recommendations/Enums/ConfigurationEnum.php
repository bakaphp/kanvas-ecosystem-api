<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Recommendations\Enums;

use Baka\Contracts\AppInterface;

enum ConfigurationEnum: string
{
    case PRODUCT_DISCOVERY_ENABLED = 'product_discovery_enabled';
    case SEMANTIC_PROFILE_STRATEGY = 'product_semantic_profile_strategy';
    case SEMANTIC_PROFILE_PROMPT = 'product_semantic_profile_prompt';
    case INTENT_LEXICON = 'product_intent_lexicon';
    case STOP_WORDS = 'product_search_stop_words';
    case TYPESENSE_QUERY_BY = 'typesense_product_query_by';
    case EMBEDDING_MODEL = 'product_discovery_embedding_model';
    case PREMIUM_MIN_PRICE = 'product_discovery_premium_min_price';
    case CHEAP_MAX_PRICE = 'product_discovery_cheap_max_price';
    case VECTOR_ALPHA = 'product_discovery_vector_alpha';
    case CACHE_TTL = 'product_discovery_cache_ttl';
    case MAX_RESULTS_PER_GROUP = 'product_discovery_max_results_per_group';
    case EXCLUDED_CATEGORIES = 'product_discovery_excluded_categories';
    case GROUP_BY_TOKENS = 'product_discovery_group_by_tokens';
    case UNAVAILABLE_PENALTY = 'product_discovery_unavailable_penalty';

    /**
     * A list setting comes back as an array from Redis but as a JSON string when a
     * human typed it into the settings UI, and as junk when they typed it wrong.
     *
     * @return array<mixed>
     */
    public function listFrom(AppInterface $app): array
    {
        $value = $app->get($this->value);

        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return is_array($value) ? $value : [];
    }
}
