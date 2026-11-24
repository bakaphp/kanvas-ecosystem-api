<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Brex;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Kanvas\Connectors\Brex\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;

/**
 * Token is read fresh on every construction, never cached in a static: under Octane the worker outlives the
 * request, so a cached client keeps serving a rotated token until the worker recycles.
 */
class Client
{
    /** A runaway cursor would loop forever against a paginating API. Bound it. */
    private const int MAX_PAGES = 100;

    private const int PAGE_SIZE = 100;

    protected PendingRequest $http;

    public function __construct(
        protected readonly AppInterface $app,
        protected readonly CompanyInterface $company,
    ) {
        $token = (string) $this->company->get(ConfigurationEnum::API_TOKEN->value);

        if ($token === '') {
            throw new ValidationException(
                'Brex API token is not configured for company ' . $this->company->getId() . '.'
            );
        }

        $this->http = Http::baseUrl(ConfigurationEnum::BASE_URL)
            ->withToken($token)
            ->acceptJson()
            ->timeout(30);
    }

    /**
     * @param array<string, mixed> $query
     * @return array<array-key, mixed>
     */
    public function get(string $endpoint, array $query = []): array
    {
        return (array) $this->http->get(ltrim($endpoint, '/'), $query)->throw()->json();
    }

    /**
     * Walks Brex's `{items, next_cursor}` pages.
     *
     * @param array<string, mixed> $query
     * @return list<array<string, mixed>>
     */
    public function getAllPages(string $endpoint, array $query = []): array
    {
        $items = [];
        $cursor = null;
        $pages = 0;

        do {
            $response = $this->get($endpoint, array_filter(
                [...$query, 'limit' => self::PAGE_SIZE, 'cursor' => $cursor],
                fn (mixed $value): bool => $value !== null,
            ));

            foreach ((array) ($response['items'] ?? []) as $item) {
                $items[] = (array) $item;
            }

            $cursor = isset($response['next_cursor']) && $response['next_cursor'] !== ''
                ? (string) $response['next_cursor']
                : null;
            $pages++;
        } while ($cursor !== null && $pages < self::MAX_PAGES);

        return $items;
    }
}
