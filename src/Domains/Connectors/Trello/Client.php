<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Trello;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Kanvas\Connectors\Trello\Enums\ConfigurationEnum;
use Kanvas\Connectors\Trello\Exceptions\TrelloException;
use Kanvas\Exceptions\ValidationException;

/**
 * Thin REST client for the Trello API (https://developer.atlassian.com/cloud/trello/rest/).
 *
 * Trello authenticates every request with `key` + `token` parameters rather than a header —
 * `withAuth()` merges them into whatever the caller passed (query string for GET, form body for
 * POST/PUT/DELETE, both of which Trello accepts).
 */
class Client
{
    public const string BASE_URL = 'https://api.trello.com/1/';

    protected string $apiKey;
    protected string $apiToken;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
    ) {
        $this->apiKey = (string) ($company->get(ConfigurationEnum::API_KEY->value)
            ?? $app->get(ConfigurationEnum::API_KEY->value) ?? '');
        $this->apiToken = (string) ($company->get(ConfigurationEnum::API_TOKEN->value)
            ?? $app->get(ConfigurationEnum::API_TOKEN->value) ?? '');

        if ($this->apiKey === '' || $this->apiToken === '') {
            throw new ValidationException('Trello API key and token are missing for this company.');
        }
    }

    /**
     * @param array<string, mixed> $query
     * @return array<array-key, mixed>
     */
    public function get(string $endpoint, array $query = []): array
    {
        $response = $this->request()->get($this->url($endpoint), $this->withAuth($query));

        return $this->handle($response, $endpoint);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<array-key, mixed>
     */
    public function post(string $endpoint, array $data = []): array
    {
        $response = $this->request()->asForm()->post($this->url($endpoint), $this->withAuth($data));

        return $this->handle($response, $endpoint);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<array-key, mixed>
     */
    public function put(string $endpoint, array $data = []): array
    {
        $response = $this->request()->asForm()->put($this->url($endpoint), $this->withAuth($data));

        return $this->handle($response, $endpoint);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<array-key, mixed>
     */
    public function delete(string $endpoint, array $data = []): array
    {
        $response = $this->request()->asForm()->delete($this->url($endpoint), $this->withAuth($data));

        return $this->handle($response, $endpoint);
    }

    protected function request(): PendingRequest
    {
        return Http::acceptJson()->timeout(20);
    }

    protected function url(string $endpoint): string
    {
        return self::BASE_URL . ltrim($endpoint, '/');
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    protected function withAuth(array $params): array
    {
        return array_filter(
            array_merge($params, ['key' => $this->apiKey, 'token' => $this->apiToken]),
            static fn (mixed $value): bool => $value !== null
        );
    }

    /**
     * @return array<array-key, mixed>
     */
    protected function handle(Response $response, string $endpoint): array
    {
        if (! $response->successful()) {
            $message = $response->json('message') ?? $response->body();

            throw new TrelloException(
                'Trello request to ' . $endpoint . ' failed (HTTP ' . $response->status() . '): '
                    . (is_string($message) ? $message : (string) json_encode($message)),
                $response->status()
            );
        }

        $body = $response->body();

        return $body === '' ? [] : (json_decode($body, true) ?? []);
    }

    /**
     * `members/me` is the cheapest authenticated endpoint Trello offers — used to confirm a
     * key/token pair actually works before it is stored.
     */
    public static function validateCredentials(string $apiKey, string $apiToken): bool
    {
        if ($apiKey === '' || $apiToken === '') {
            throw new ValidationException('Trello API key and token are required.');
        }

        $response = Http::acceptJson()
            ->timeout(15)
            ->get(self::BASE_URL . 'members/me', ['key' => $apiKey, 'token' => $apiToken]);

        if ($response->status() === 401) {
            throw new ValidationException('Trello rejected the provided API key/token.');
        }

        if (! $response->successful()) {
            throw new ValidationException('Trello validation failed with HTTP ' . $response->status() . '.');
        }

        return true;
    }
}
