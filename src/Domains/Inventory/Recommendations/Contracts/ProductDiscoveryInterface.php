<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Recommendations\Contracts;

use Kanvas\Inventory\Recommendations\Discovery\DiscoveryQuery;

/**
 * An engine adapter. It translates a DiscoveryQuery into its own syntax and
 * generates candidate ids only — never decides what the caller may see. The
 * caller re-reads each id under its own tenant scope and re-checks every hard
 * constraint against the database.
 */
interface ProductDiscoveryInterface
{
    /**
     * @param float[]|null $tasteVector recency-weighted profile of what this
     *                                  shopper engaged with, when one exists
     *
     * @return list<int> product ids, best first
     */
    public function search(DiscoveryQuery $query, int $limit, ?array $tasteVector = null): array;

    /** Searches by meaning, so the shopper's own sentence is worth sending as is. */
    public function supportsVectors(): bool;

    /**
     * Whether a filter on this field is safe. An engine answers a filter on an
     * undeclared field with nothing (Typesense) or an error (Algolia facets), so a
     * missing optional field costs that constraint, not every result.
     */
    public function canFilterOn(string $field): bool;
}
