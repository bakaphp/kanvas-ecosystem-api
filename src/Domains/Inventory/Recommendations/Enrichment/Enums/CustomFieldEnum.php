<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Recommendations\Enrichment\Enums;

/**
 * The blurb's field name lives in Recommendations\Enums\SearchFieldEnum, which the index reads too.
 */
enum CustomFieldEnum: string
{
    case ENRICHMENT_HASH = 'search_enrichment_hash';
}
