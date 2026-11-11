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
    public const string ENGINE_TYPESENSE = 'typesense';
    public const string ENGINE_ALGOLIA = 'algolia';
    public const string ENGINE_SQL = 'sql';

    public function __construct(
        private readonly AppInterface $app,
        private readonly CompanyInterface $company,
    ) {
    }

    public function resolve(): ProductDiscoveryInterface
    {
        return match ($this->engine()) {
            self::ENGINE_TYPESENSE => new TypesenseProductDiscoveryService($this->app, $this->company),
            self::ENGINE_ALGOLIA => new AlgoliaProductDiscoveryService($this->app, $this->company),
            default => $this->fallback(),
        };
    }

    public function fallback(): ProductDiscoveryInterface
    {
        return new SqlProductDiscoveryService(
            $this->app,
            $this->company,
            new SearchTermTokenizerService($this->app),
        );
    }

    /** The one place the index-name rule lives; mirrors Products::searchableAs(). */
    public static function collectionName(AppInterface $app): string
    {
        return (string) config('scout.prefix') . (string) ($app->get('app_custom_product_index') ?? 'product_index');
    }

    /**
     * The label discovery reports and logs. An engine with no discovery adapter
     * (meilisearch, null, unset) runs the SQL fallback and says so.
     */
    public function engine(): string
    {
        return self::engineFor($this->app);
    }

    /** For setup tooling that has an app but no company to build a resolver with. */
    public static function engineFor(AppInterface $app): string
    {
        $engine = $app->get('products_search_engine')
            ?? $app->get('search_engine')
            ?? config('scout.driver');

        return in_array($engine, [self::ENGINE_TYPESENSE, self::ENGINE_ALGOLIA], true)
            ? (string) $engine
            : self::ENGINE_SQL;
    }
}
