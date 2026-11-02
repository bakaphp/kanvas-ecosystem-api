<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Recommendations\Services;

use Baka\Contracts\AppInterface;
use Kanvas\Inventory\Recommendations\Contracts\DiscoveryIndexAdapter;

class DiscoveryIndexAdapterResolverService
{
    /**
     * Null on the SQL fallback: there is no index to prepare.
     */
    public function resolve(AppInterface $app): ?DiscoveryIndexAdapter
    {
        return match (ProductDiscoveryResolver::engineFor($app)) {
            ProductDiscoveryResolver::ENGINE_ALGOLIA => new AlgoliaDiscoveryIndexService($app),
            ProductDiscoveryResolver::ENGINE_TYPESENSE => new TypesenseDiscoveryIndexService($app),
            default => null,
        };
    }
}
