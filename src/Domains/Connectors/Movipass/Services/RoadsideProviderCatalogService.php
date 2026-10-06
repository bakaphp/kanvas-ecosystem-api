<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Services;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Facades\Cache;
use Kanvas\Connectors\Movipass\Enums\ConfigurationEnum;
use Kanvas\Connectors\Movipass\Enums\RoadsideProviderEndpointEnum;

/**
 * The provider catalogs (states, causes, departments, municipalities, providers, assistance types)
 * change on their schedule, not ours, and every case intake needs several of them. Cache them per
 * app so building a case is not six extra HTTP round trips while an operator is on the phone.
 */
class RoadsideProviderCatalogService
{
    public function __construct(private readonly AppInterface $app)
    {
    }

    public function states(): array
    {
        return $this->remember(RoadsideProviderEndpointEnum::CATALOG_STATES->value);
    }

    public function causes(): array
    {
        return $this->remember(RoadsideProviderEndpointEnum::CATALOG_CAUSES->value);
    }

    public function departments(): array
    {
        return $this->remember(RoadsideProviderEndpointEnum::CATALOG_DEPARTMENTS->value);
    }

    public function municipalities(int|string $departmentId): array
    {
        return $this->remember(RoadsideProviderEndpointEnum::CATALOG_MUNICIPALITIES->withDepartment($departmentId));
    }

    public function providers(): array
    {
        return $this->remember(RoadsideProviderEndpointEnum::CATALOG_PROVIDERS->value);
    }

    public function assistanceTypes(): array
    {
        return $this->remember(RoadsideProviderEndpointEnum::CATALOG_ASSISTANCE_TYPES->value);
    }

    public function flush(): void
    {
        foreach ([
            RoadsideProviderEndpointEnum::CATALOG_STATES->value,
            RoadsideProviderEndpointEnum::CATALOG_CAUSES->value,
            RoadsideProviderEndpointEnum::CATALOG_DEPARTMENTS->value,
            RoadsideProviderEndpointEnum::CATALOG_PROVIDERS->value,
            RoadsideProviderEndpointEnum::CATALOG_ASSISTANCE_TYPES->value,
        ] as $endpoint) {
            Cache::forget($this->cacheKey($endpoint));
        }
    }

    private function remember(string $endpoint): array
    {
        $ttl = (int) ($this->app->get(ConfigurationEnum::ROADSIDE_PROVIDER_CATALOG_CACHE_TTL->value)
            ?? ConfigurationEnum::ROADSIDE_PROVIDER_DEFAULT_CATALOG_CACHE_TTL->value);

        return Cache::remember(
            $this->cacheKey($endpoint),
            $ttl,
            fn (): array => new RoadsideProviderClient($this->app)->get($endpoint),
        );
    }

    private function cacheKey(string $endpoint): string
    {
        return sprintf('movipass-roadside-provider-catalog-%s-%s', $this->app->getId(), md5($endpoint));
    }
}
