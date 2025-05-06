<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Airalo;

use Baka\Contracts\AppInterface;
use GuzzleHttp\Client as GuzzleClient;
use Kanvas\Connectors\Airalo\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;

class Client
{
    protected GuzzleClient $client;
    protected string $baseUrl;
    protected string $baseUrlV2;
    protected string $clientId;
    protected string $clientSecret;
    protected string $grantType;

    public function __construct(
        protected AppInterface $app
    ) {
        $this->baseUrl = $this->app->get(ConfigurationEnum::BASE_URL->value);
        $this->baseUrlV2 = $this->app->get(ConfigurationEnum::BASE_URL_V2->value);
        $this->clientId = $this->app->get(ConfigurationEnum::CLIENT_ID->value);
        $this->clientSecret = $this->app->get(ConfigurationEnum::CLIENT_SECRET->value);
        $this->grantType = $this->app->get(ConfigurationEnum::GRANT_TYPE->value);

        if (empty($this->clientId) || empty($this->clientSecret)) {
            throw new ValidationException('Airalo configuration is missing');
        }

        $this->client = new GuzzleClient();
    }

    protected function request(string $method, string $uri, array $body = [], bool $useV2 = false): array
    {
        $baseUrl = $useV2 ? $this->baseUrlV2 : $this->baseUrl;
        $headers = [
            'Content-Type' => 'application/json',
        ];

        // Only add Authorization header for V2 endpoints
        if ($useV2) {
            $headers['Authorization'] = 'Bearer ' . $this->getAccessToken();
        }

        $options = [
            'headers' => $headers,
        ];

        if (! empty($body)) {
            $options['json'] = $body;
        }

        $response = $this->client->request($method, $baseUrl . $uri, $options);

        return json_decode($response->getBody()->getContents(), true);
    }

    protected function getAccessToken(): string
    {
        if (! app()->environment('production')) {
            return $this->app->get(ConfigurationEnum::TEST_ACCESS_TOKEN->value);
        }

        $response = $this->request('POST', '/token', [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type' => $this->grantType,
        ]);

        return $response['data']['access_token'];
    }

    public function get(string $uri, array $body = [], bool $useV2 = false): array
    {
        return $this->request('GET', $uri, $body, $useV2);
    }

    public function post(string $uri, array $body = [], bool $useV2 = false): array
    {
        return $this->request('POST', $uri, $body, $useV2);
    }

    public function put(string $uri, array $body = [], bool $useV2 = false): array
    {
        return $this->request('PUT', $uri, $body, $useV2);
    }

    public function delete(string $uri, bool $useV2 = false): array
    {
        return $this->request('DELETE', $uri, [], $useV2);
    }
}
