<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Recommendations\Contracts;

use Kanvas\Inventory\Recommendations\Discovery\DiscoveryQuery;

/**
 * An engine whose hits carry the whole product record. Discovery then answers from
 * the documents alone — no database read per search — and the SQL fallback is the
 * only path that touches the database. The index is kept current by the reindex
 * every product, variant and channel-price write already triggers.
 */
interface ServesSearchDocuments
{
    /**
     * @param float[]|null $tasteVector
     *
     * @return list<array<string, mixed>> engine documents, best first, each with an `id`
     */
    public function searchDocuments(DiscoveryQuery $query, int $limit, ?array $tasteVector = null): array;
}
