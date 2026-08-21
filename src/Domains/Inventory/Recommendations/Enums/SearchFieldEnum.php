<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Recommendations\Enums;

/**
 * Names of the discovery fields carried into the search index.
 *
 * Canonical here rather than in the connector that writes them: `Products` reads
 * these while indexing, and a core domain model must not depend on a connector.
 * Whatever produces the blurb — the ProductEnrichment connector today, an import
 * or a merchant tomorrow — writes to this name.
 */
enum SearchFieldEnum: string
{
    case BLURB = 'search_blurb';
}
