<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Recommendations\Contracts;

use Kanvas\Inventory\Recommendations\DataTransferObject\ProductIntent;

/**
 * Turns a shopper's sentence into candidate product ids.
 *
 * Implementations only generate candidates — they never decide what the caller
 * is allowed to see. The caller re-reads every id from the database under its
 * own tenant scope, so a mis-scoped or stale index cannot leak a row.
 */
interface ProductDiscoveryInterface
{
    /**
     * @param float[]|null $tasteVector recency-weighted profile of what this
     *                                  shopper engaged with, when one exists
     *
     * @return list<int> product ids, best first
     */
    public function search(ProductIntent $intent, int $limit, ?array $tasteVector = null): array;
}
