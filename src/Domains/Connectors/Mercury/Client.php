<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mercury;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use GuzzleHttp\Client as GuzzleClient;
use Kanvas\Connectors\Mercury\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;

/**
 * Read-only Mercury REST client.
 *
 * The token is company-level (one app, many tenants, each with its own Mercury org) and is read fresh on
 * every construction — never cached in a static. Under Octane the worker outlives the request, so a cached
 * client would keep serving a rotated token until the worker recycled. Building a Guzzle client is a few
 * string assignments; there is nothing to save by caching it.
 */
class Client
{
    protected GuzzleClient $client;

    /**
     * $httpClient is a test seam — pass a Guzzle client with a MockHandler to exercise the real
     * request-building path against canned responses. Production always leaves it null.
     */
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
     * Serializes array parameters as REPEATED KEYS (`accountId=a&accountId=b`), not Guzzle's default
     * bracket-indexed form (`accountId[0]=a`).
     *
     * This is not a style preference. Mercury SILENTLY IGNORES a bracket-indexed filter — it returns 200 OK
     * with unfiltered results rather than erroring. Asking `/transactions?accountId[0]=<checking>` returns
     * transactions from every account you own, and the caller has no way to tell. That put savings and
     * credit-card movements onto the checking account's bank row, posting their cash to the wrong GL account.
     *
     * The failure is worse than it sounds because it's load-dependent: at a small `limit` the first page
     * happens to come back clean, so it looks like it works right up until it doesn't.
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

    /**
     * Raw bytes, for statement PDFs. Goes through the authenticated client rather than a bare URL fetch —
     * the host is fixed (api.mercury.com), so there's no user-controlled URL and no SSRF surface.
     */
    public function getRaw(string $endpoint): string
    {
        return (string) $this->client->get(ltrim($endpoint, '/'))->getBody();
    }
}
