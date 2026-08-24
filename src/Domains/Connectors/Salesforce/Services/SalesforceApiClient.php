<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Salesforce\Services;

use Closure;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Kanvas\Exceptions\ValidationException;

/**
 * Thin REST wrapper over the Salesforce `/services/data/{version}` API. Built fresh per call by
 * `Client::getInstance()` — never cached in a static property (Octane rule: stale credentials
 * survive across requests on the same worker).
 */
final class SalesforceApiClient
{
    /**
     * Conservative proactive cap — Salesforce's actual per-second limit varies by org edition,
     * this just keeps a bulk pull from bursting hard enough to trip it.
     */
    private const int MAX_REQUESTS_PER_SECOND = 10;

    private const int MAX_RATE_LIMIT_RETRIES = 3;

    private const int RATE_LIMIT_RETRY_DELAY_SECONDS = 2;

    private bool $hasRetried = false;

    private int $rateLimitRetries = 0;

    public function __construct(
        private string $instanceUrl,
        private string $accessToken,
        private readonly string $apiVersion,
        private readonly ?Closure $onUnauthorized = null,
        private readonly ?Closure $sleeper = null,
    ) {
    }

    public function create(string $sobject, array $data): string
    {
        $response = $this->send('post', "/services/data/{$this->apiVersion}/sobjects/{$sobject}", $data);

        return (string) ($response->json('id') ?? '');
    }

    public function update(string $sobject, string $id, array $data): void
    {
        $this->send('patch', "/services/data/{$this->apiVersion}/sobjects/{$sobject}/{$id}", $data);
    }

    public function find(string $sobject, string $id): ?array
    {
        $response = $this->send(
            'get',
            "/services/data/{$this->apiVersion}/sobjects/{$sobject}/{$id}",
            allowNotFound: true,
        );

        return $response->status() === 404 ? null : ($response->json() ?? []);
    }

    public function query(string $soql): array
    {
        $response = $this->send('get', "/services/data/{$this->apiVersion}/query", ['q' => $soql]);

        return $response->json() ?? [];
    }

    /**
     * Follows Salesforce's pagination `nextRecordsUrl` (max 2000 records per page). It already
     * arrives as a path relative to `instanceUrl` (e.g. `/services/data/v60.0/query/01gXX...-2000`),
     * so it goes through `send()` unchanged — same as `query()`.
     */
    public function queryMore(string $nextRecordsUrl): array
    {
        $response = $this->send('get', $nextRecordsUrl);

        return $response->json() ?? [];
    }

    public function delete(string $sobject, string $id): void
    {
        $this->send('delete', "/services/data/{$this->apiVersion}/sobjects/{$sobject}/{$id}", allowNotFound: true);
    }

    /**
     * @return array{sobjects: list<array{name: string, label: string, custom: bool, keyPrefix: ?string}>}
     */
    public function describeGlobal(): array
    {
        $response = $this->send('get', "/services/data/{$this->apiVersion}/sobjects/");

        return $response->json() ?? [];
    }

    /**
     * @return array{fields: list<array<string, mixed>>}
     */
    public function describeObject(string $sobject): array
    {
        $response = $this->send('get', "/services/data/{$this->apiVersion}/sobjects/{$sobject}/describe");

        return $response->json() ?? [];
    }

    private function send(
        string $method,
        string $path,
        array $data = [],
        bool $allowNotFound = false,
    ): Response {
        $this->throttle();

        $request = Http::withToken($this->accessToken)->acceptJson();

        $response = match ($method) {
            'get' => $request->get($this->instanceUrl . $path, $data),
            'post' => $request->post($this->instanceUrl . $path, $data),
            'patch' => $request->patch($this->instanceUrl . $path, $data),
            'delete' => $request->delete($this->instanceUrl . $path),
        };

        if ($response->status() === 401 && $this->onUnauthorized !== null && ! $this->hasRetried) {
            $this->hasRetried = true;
            $refreshed = ($this->onUnauthorized)();
            $this->accessToken = $refreshed['access_token'];
            $this->instanceUrl = $refreshed['instance_url'] ?: $this->instanceUrl;

            return $this->send($method, $path, $data, $allowNotFound);
        }

        if ($this->isRateLimitError($response)) {
            if ($this->rateLimitRetries >= self::MAX_RATE_LIMIT_RETRIES) {
                throw new ValidationException(
                    'Salesforce rate limit exceeded after ' . $this->rateLimitRetries . ' retries (HTTP ' . $response->status() . '): ' . $response->body()
                );
            }

            $this->rateLimitRetries++;
            $this->wait(self::RATE_LIMIT_RETRY_DELAY_SECONDS);

            return $this->send($method, $path, $data, $allowNotFound);
        }

        if ($response->failed() && ! ($allowNotFound && $response->status() === 404)) {
            throw new ValidationException(
                'Salesforce API error (HTTP ' . $response->status() . '): ' . $response->body()
            );
        }

        return $response;
    }

    /**
     * Proactive throttle before every request — `RateLimiter::attempt()` against a per-org key
     * derived from the instance URL, mirroring `RespondIO\Client::throttle()`.
     */
    private function throttle(): void
    {
        $key = 'salesforce_api:' . md5($this->instanceUrl);

        $executed = RateLimiter::attempt($key, self::MAX_REQUESTS_PER_SECOND, fn () => true, 1);

        if (! $executed) {
            $this->wait(max(1, RateLimiter::availableIn($key)));
        }
    }

    /**
     * Salesforce doesn't always return a clean 429 — a rate limit can come back as a 400/403 with
     * `errorCode: "REQUEST_LIMIT_EXCEEDED"` in the JSON body, so the body has to be checked before
     * treating a 4xx as a real (fatal) error.
     */
    private function isRateLimitError(Response $response): bool
    {
        if ($response->status() === 429) {
            return true;
        }

        if (! in_array($response->status(), [400, 403], true)) {
            return false;
        }

        $body = $response->json();
        $errors = is_array($body) && array_is_list($body) ? $body : [$body];

        foreach ($errors as $error) {
            if (is_array($error) && ($error['errorCode'] ?? null) === 'REQUEST_LIMIT_EXCEEDED') {
                return true;
            }
        }

        return false;
    }

    private function wait(int $seconds): void
    {
        if ($this->sleeper !== null) {
            ($this->sleeper)($seconds);

            return;
        }

        sleep($seconds);
    }
}
