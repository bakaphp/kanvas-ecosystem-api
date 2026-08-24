<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Recommendations\Enums;

/**
 * Canonical here, not in the connector that writes them: `Products` reads these
 * while indexing, and a core model must not depend on a connector.
 */
enum SearchFieldEnum: string
{
    case BLURB = 'search_blurb';

    /** A product ATTRIBUTE the enrichment writes, not a custom field like the blurb. */
    case AUDIENCE = 'audience';
}
