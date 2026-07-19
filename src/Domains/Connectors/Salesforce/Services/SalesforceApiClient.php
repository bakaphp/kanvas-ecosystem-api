<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Salesforce\Services;

use Closure;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Kanvas\Exceptions\ValidationException;

/**
 * Thin REST wrapper over the Salesforce `/services/data/{version}` API. Built fresh per call by
 * `Client::getInstance()` — never cached in a static property (Octane rule: stale credentials
 * survive across requests on the same worker).
 */
final class SalesforceApiClient
{
    private bool $hasRetried = false;

    public function __construct(
        private string $instanceUrl,
        private string $accessToken,
        private readonly string $apiVersion,
        private readonly ?Closure $onUnauthorized = null,
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

    public function delete(string $sobject, string $id): void
    {
        $this->send('delete', "/services/data/{$this->apiVersion}/sobjects/{$sobject}/{$id}", allowNotFound: true);
    }

    private function send(
        string $method,
        string $path,
        array $data = [],
        bool $allowNotFound = false,
    ): Response {
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

        if ($response->failed() && ! ($allowNotFound && $response->status() === 404)) {
            throw new ValidationException(
                'Salesforce API error (HTTP ' . $response->status() . '): ' . $response->body()
            );
        }

        return $response;
    }
}
