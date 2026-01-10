<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DriveCentric;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\DriveCentric\Enums\ConfigurationEnum;
use Kanvas\Connectors\DriveCentric\Exceptions\DriveCentricException;

class Client
{
    protected ?string $cachedToken = null;

    public function __construct(
        protected Apps $app,
        protected Companies $company,
    ) {
    }

    protected function makeClient(): PendingRequest
    {
        return Http::withUrlParameters([
            'endpoint' => $this->app->get(ConfigurationEnum::BASE_URL->value),
            'storeId' => $this->company->get(ConfigurationEnum::STORE_ID->value),
        ]);
    }

    protected function getToken(): string
    {
        $cacheKey = 'drive_centric_token_' . $this->app->getId() . '_' . $this->company->getId();

        return Cache::remember($cacheKey, 3500, function () {
            $client = $this->makeClient();
            $response = $client->post('{+endpoint}/api/authentication/token', [
                'clientId' => $this->app->get(ConfigurationEnum::API_KEY->value),
                'clientSecret' => $this->app->get(ConfigurationEnum::API_SECRET_KEY->value),
            ]);

            if (! $response->successful()) {
                throw new DriveCentricException(
                    'Failed to authenticate with DriveCentric: ' . $response->body()
                );
            }

            return $response->json('idToken');
        });
    }

    public function getClient(): PendingRequest
    {
        $token = $this->getToken();

        return $this->makeClient()
            ->withToken($token);
    }

    /**
     * Handle API response and throw exception with full error details if failed.
     */
    protected function handleResponse(Response $response): Response
    {
        if ($response->failed()) {
            $body = $response->json() ?? $response->body();
            $message = is_array($body)
                ? json_encode($body, JSON_PRETTY_PRINT)
                : $body;

            throw new DriveCentricException(
                "DriveCentric API Error (HTTP {$response->status()}): {$message}"
            );
        }

        return $response;
    }

    public function get(string $endpoint, array $params = []): Response
    {
        $response = $this->getClient()->get('{+endpoint}' . $endpoint, $params);

        return $this->handleResponse($response);
    }

    public function post(string $endpoint, array $data = []): Response
    {
        $response = $this->getClient()->post('{+endpoint}' . $endpoint, $data);

        return $this->handleResponse($response);
    }

    public function put(string $endpoint, array $data = []): Response
    {
        $response = $this->getClient()->put('{+endpoint}' . $endpoint, $data);

        return $this->handleResponse($response);
    }

    public function delete(string $endpoint, array $data = []): Response
    {
        $response = $this->getClient()->delete('{+endpoint}' . $endpoint, $data);

        return $this->handleResponse($response);
    }

    public function getStoreId(): string
    {
        return $this->company->get(ConfigurationEnum::STORE_ID->value);
    }
}
