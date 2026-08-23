<?php

declare(strict_types=1);

/*
 * Defaults for natural-language product discovery. English only — a tenant adds
 * its own language through the `product_intent_lexicon` app setting, which is
 * MERGED over these terms rather than replacing them (a storefront in the DR
 * receives both "menos de $50" and "under 50" from the same audience).
 *
 * Only hard numeric constraints live here. Everything semantic is handled by the
 * multilingual embedding, which needs no per-language configuration.
 */
return [
    'intent_lexicon' => [
        'max_price' => [
            'no more than',
            'less than',
            'under',
            'below',
            'up to',
            'max',
        ],
        'min_price' => [
            'more than',
            'starting at',
            'over',
            'above',
            'from',
        ],
        'premium' => [
            'expensive',
            'luxury',
            'premium',
            'high end',
            'exclusive',
        ],
        'cheap' => [
            'inexpensive',
            'affordable',
            'budget',
            'cheap',
        ],
    ],

    /*
     * Dropped before term matching so "regalo para mi esposo" searches the nouns
     * rather than the whole phrase. Only used by the SQL fallback — an engine
     * does its own tokenizing.
     */
    'stop_words' => [
        'para', 'mi', 'de', 'un', 'una', 'el', 'la', 'los', 'las', 'y', 'o',
        'con', 'que', 'algo', 'regalo',
        'the', 'for', 'my', 'a', 'an', 'to', 'of', 'with', 'and', 'or',
        'something', 'gift',
    ],

    /*
     * Comma-separated Typesense fields the hybrid search matches on. Include
     * `embedding` ONLY once the collection declares the auto-embed field —
     * naming a field the collection does not have makes Typesense reject the
     * whole search, and the vector half is skipped when it is absent.
     */
    'typesense_query_by' => 'search_blurb,name,description',

    'typesense_query_by_weights' => '3,2,1',

    /*
     * Applied only when the sentence carries a vague price signal ("something
     * luxurious") and no explicit number. A tenant with a different price band
     * overrides both on the app.
     */
    /*
     * Categories a shopper never means, however well they match. Gift wrapping is
     * the canonical case: it scores highly on a gift query and is not a gift.
     * Matched on the category name, case-insensitively; a tenant overrides with
     * the `product_discovery_excluded_categories` app setting.
     */
    'excluded_categories' => [],

    'premium_min_price' => 10000.0,
    'cheap_max_price' => 50.0,
];
