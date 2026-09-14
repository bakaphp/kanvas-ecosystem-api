<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mercury;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ClientException;
use Kanvas\Connectors\Mercury\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;

/**
 * Token is read fresh on every construction, never cached in a static: under Octane the worker outlives the
 * request, so a cached client keeps serving a rotated token until the worker recycles.
 */
class Client
{
    protected GuzzleClient $client;

    public function __construct(
        protected readonly AppInterface $app,
        protected readonly CompanyInterface $company,
        ?GuzzleClient $httpClient = null,
    ) {
        $token = (string) $this->company->get(ConfigurationEnum::API_TOKEN->value);

        if ($token === '') {
            throw new ValidationException(
                'Mercury API token is not configured for company ' . $this->company->getId() . '.'
            );
        }

        $baseUrl = (string) ($this->company->get(ConfigurationEnum::BASE_URL->value)
            ?: ConfigurationEnum::DEFAULT_BASE_URL);

        $this->client = $httpClient ?? new GuzzleClient([
            'base_uri' => rtrim($baseUrl, '/') . '/',
            'timeout' => 30,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $token,
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $query
     * @return array<array-key, mixed>
     */
    public function get(string $endpoint, array $query = []): array
    {
        $queryString = $this->buildQuery($query);
        $uri = ltrim($endpoint, '/') . ($queryString !== '' ? '?' . $queryString : '');

        $response = $this->client->get($uri);

        return (array) json_decode((string) $response->getBody(), true);
    }

    /**
     * A 404 is an ANSWER, not a fault — the resource isn't there (or isn't visible to this token). Callers
     * that model absence as null use this so a missing record doesn't throw and get reported to Sentry.
     *
     * @param array<string, mixed> $query
     * @return array<array-key, mixed>|null
     */
    public function getOrNull(string $endpoint, array $query = []): ?array
    {
        try {
            return $this->get($endpoint, $query);
        } catch (ClientException $e) {
            if ($e->getResponse()->getStatusCode() === 404) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * Arrays serialize as REPEATED KEYS (`accountId=a&accountId=b`), not Guzzle's default `accountId[0]=a`.
     * Mercury SILENTLY IGNORES the bracket form — 200 OK, unfiltered results — so a scoped request quietly
     * returns every account's transactions. Load-dependent too: a small `limit` comes back clean.
     *
     * @param array<string, mixed> $query
     */
    private function buildQuery(array $query): string
    {
        $parts = [];

        foreach ($query as $key => $value) {
            foreach ((array) $value as $item) {
                if ($item === null || $item === '') {
                    continue;
                }

                $parts[] = rawurlencode($key) . '=' . rawurlencode((string) $item);
            }
        }

        return implode('&', $parts);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<array-key, mixed>
     */
    public function post(string $endpoint, array $body): array
    {
        $response = $this->client->post(ltrim($endpoint, '/'), ['json' => $body]);

        return (array) json_decode((string) $response->getBody(), true);
    }

    public function delete(string $endpoint): void
    {
        $this->client->delete(ltrim($endpoint, '/'));
    }

    public function getRaw(string $endpoint): string
    {
        return (string) $this->client->get(ltrim($endpoint, '/'))->getBody();
    }

    public function getBinary(string $endpoint, string $accept = 'application/octet-stream'): string
    {
        $response = $this->client->get(ltrim($endpoint, '/'), [
            'headers' => ['Accept' => $accept],
        ]);

        return (string) $response->getBody();
    }
}
