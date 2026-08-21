<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Recommendations\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Inventory\Recommendations\Contracts\ProductDiscoveryInterface;

/**
 * Picks the discovery backend for a tenant.
 *
 * Mirrors SearchEngineResolver's precedence exactly — model-specific setting,
 * then app default, then the global driver. Getting that order wrong would make
 * discovery disagree with how Products::search() actually routes, and the two
 * would quietly index and query different engines.
 */
class ProductDiscoveryResolver
{
    public function __construct(
        private readonly AppInterface $app,
        private readonly CompanyInterface $company,
    ) {
    }

    public function resolve(): ProductDiscoveryInterface
    {
        return $this->isOnTypesense()
            ? new TypesenseProductDiscoveryService($this->app, $this->company)
            : $this->fallback();
    }

    public function fallback(): ProductDiscoveryInterface
    {
        return new SqlProductDiscoveryService(
            $this->app,
            $this->company,
            new SearchTermTokenizerService($this->app),
        );
    }

    public function isOnTypesense(): bool
    {
        $engine = $this->app->get('products_search_engine')
            ?? $this->app->get('search_engine')
            ?? config('scout.driver');

        return $engine === 'typesense';
    }
}
