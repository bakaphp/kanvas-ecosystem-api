<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Reference data belongs to the insurer, so it is cached rather than copied into our
 * tables — nothing joins against it. The mechanism is shared but the policy is not:
 * only the adapter knows which of its catalogs are stable, so it passes its own TTL
 * instead of being wrapped in a decorator that would have to guess.
 */
class CatalogCache
{
    /**
     * @param array<string, mixed> $params
     * @param Closure(): array<array-key, mixed> $callback
     *
     * @return array<array-key, mixed>
     */
    public static function remember(
        AppInterface $app,
        CompanyInterface $company,
        string $provider,
        string $catalog,
        int $ttl,
        Closure $callback,
        array $params = [],
    ): array {
        if ($ttl <= 0) {
            return $callback();
        }

        return Cache::remember(
            self::key($app, $company, $provider, $catalog, $params),
            $ttl,
            $callback
        );
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function key(
        AppInterface $app,
        CompanyInterface $company,
        string $provider,
        string $catalog,
        array $params = [],
    ): string {
        // Credentials are company-scoped and environments differ per company, so a
        // key shared across tenants would serve one aliado another's QA data.
        $key = sprintf(
            'insuranceCatalog-%d-%d-%s-%s',
            $app->getId(),
            $company->getId(),
            $provider,
            $catalog
        );

        if ($params === []) {
            return $key;
        }

        ksort($params);

        return $key . '-' . md5((string) json_encode($params));
    }
}
