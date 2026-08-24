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

        /*
         * Who the gift is FOR. A filter, not a ranking signal — an embedding reads
         * "for a man" and "para mujeres" as similar rather than contradictory.
         *
         * Relationship words over bare "man"/"woman": a shopper says "para mi novia"
         * far more often than "para una mujer". Accents fold before matching, so a
         * tenant adding "mama" also covers "mamá".
         */
        'audience_male' => [
            'for him', 'boyfriend', 'husband', 'father', 'dad', 'brother',
            'son', 'uncle', 'man', 'men', 'male',
        ],
        'audience_female' => [
            'for her', 'girlfriend', 'wife', 'mother', 'mom', 'sister',
            'daughter', 'aunt', 'woman', 'women', 'female',
        ],
        'audience_kids' => [
            'kid', 'kids', 'child', 'children', 'boy', 'girl',
        ],
        'audience_baby' => [
            'baby', 'newborn', 'infant',
        ],
        'audience_teen' => [
            'teen', 'teenager', 'adolescent',
        ],
        'audience_senior' => [
            'senior', 'elderly', 'retiree', 'grandmother', 'grandma',
            'grandfather', 'grandpa',
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
     * Leading name tokens forming the per-group cap key. The whole name is too fine:
     * "Perfume Premium 31/37/38" are three names and one product. 0 = whole name.
     */
    'group_by_tokens' => 2,

    /*
     * Places an unavailable product drops in the ranking. A penalty, not a
     * partition: sorting all in-stock products first lets a weak match leapfrog a
     * strong one. 0 disables; a value past the page size is a hard partition.
     */
    'unavailable_penalty' => 3,

    /*
     * Gift wrapping is the canonical case: it scores highly on a gift query and is
     * not a gift. Matched on the category name, case-insensitively.
     */
    'excluded_categories' => [],

    /*
     * Applied only when the sentence carries a vague price signal ("something
     * luxurious") and no explicit number. A tenant with a different price band
     * overrides both on the app.
     */
    'premium_min_price' => 10000.0,
    'cheap_max_price' => 50.0,
];
