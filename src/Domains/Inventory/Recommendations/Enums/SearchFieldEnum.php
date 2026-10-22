<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Recommendations\Enums;

/**
 * The fields `Products` indexes for discovery. Enrichment writes them;
 * `Enrichment\Enums\AttributeEnum::AUDIENCE` must stay equal to AUDIENCE, since that enum
 * also drives which attributes are kept out of the enrichment prompt.
 */
enum SearchFieldEnum: string
{
    case BLURB = 'search_blurb';

    /** A product ATTRIBUTE the enrichment writes, not a custom field like the blurb. */
    case AUDIENCE = 'audience';
}
