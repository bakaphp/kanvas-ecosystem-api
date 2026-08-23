<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Recommendations\Enums;

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
}
