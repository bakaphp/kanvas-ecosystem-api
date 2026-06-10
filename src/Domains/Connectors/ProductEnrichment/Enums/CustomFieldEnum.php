<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ProductEnrichment\Enums;

enum CustomFieldEnum: string
{
    // Facets live as attributes, vibe labels as tags. These custom fields only
    // hold the non-facet bits: the semantic blurb (embedding source) + dedup hash.
    // `blurb` is generic on purpose — the gift framing comes from the app's
    // enrichment prompt, not the field name (this connector is multi-vertical).
    case BLURB = 'search_blurb';
    case ENRICHMENT_HASH = 'search_enrichment_hash';
}
