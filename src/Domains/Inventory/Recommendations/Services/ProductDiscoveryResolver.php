<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Recommendations\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Inventory\Recommendations\Contracts\ProductDiscoveryInterface;

/**
 * Mirrors SearchEngineResolver's precedence exactly. Getting the order wrong
 * would have discovery query a different engine than indexing writes to.
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
