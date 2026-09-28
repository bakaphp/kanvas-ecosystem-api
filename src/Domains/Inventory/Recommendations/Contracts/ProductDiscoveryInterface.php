<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Recommendations\Contracts;

use Kanvas\Inventory\Recommendations\DataTransferObject\ProductIntent;

/**
 * Generates candidate ids only — never decides what the caller may see. The
 * caller re-reads each id under its own tenant scope.
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
