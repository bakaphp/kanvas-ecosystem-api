<?php

declare(strict_types=1);

namespace Kanvas\Connectors\IPlus;

use Baka\Contracts\AppInterface;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Redis;
use Kanvas\Connectors\IPlus\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;

class Client
{
    protected string $authBaseUrl;
    protected string $apiBaseUrl = 'https://api.iplus.tech';
    protected GuzzleClient $httpClient;
    protected string $clientId;
    protected string $clientSecret;
    protected string $username;
    protected string|int $password;
    protected string $redisKeyPrefix;

    public function __construct(
        protected AppInterface $app
    ) {
        $this->authBaseUrl = $this->app->get(ConfigurationEnum::AUTH_BASE_URL->value);
        $this->clientId = $this->app->get(ConfigurationEnum::CLIENT_ID->value);
        $this->username = $this->app->get(ConfigurationEnum::USERNAME->value);
        $this->password = $this->app->get(ConfigurationEnum::PASSWORD->value);
        $this->clientSecret = $this->app->get(ConfigurationEnum::CLIENT_SECRET->value);

        if (empty($this->clientId) || empty($this->clientSecret)) {
            throw new ValidationException('IPlus keys are not set for ' . $this->app->name);
        }

        // Define a unique Redis key prefix based on the app ID
        $this->redisKeyPrefix = ConfigurationEnum::I_PLUS_REDIS_KEY_PREFIX->value . $this->app->getId();

        $this->httpClient = new GuzzleClient([
            'timeout' => 10,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    public function getValidAccessToken(): string
    {
        // Try to get token from Redis using the app-specific key
        $cachedToken = Redis::get($this->redisKeyPrefix);
        if ($cachedToken) {
            $tokenData = json_decode($cachedToken, true);
            if ($this->isTokenValid($tokenData)) {
                return $tokenData['access_token'];
            }
        }

        // If no valid cached token, get a new one
        return $this->requestNewAccessToken();
    }

    protected function isTokenValid(array $tokenData): bool
    {
        return isset($tokenData['expires']) && $tokenData['expires'] > time();
    }

    protected function requestNewAccessToken(): string
    {
        try {
            $response = $this->httpClient->post($this->authBaseUrl . '/oauth2/token', [
                'form_params' => [
                    'grant_type' => 'password',
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'username' => $this->username,
                    'password' => $this->password,
                    'scope' => 'iplus.read iplus.write',
                ],
            ]);

            $tokenData = json_decode($response->getBody()->getContents(), true);

            // Cache the token with the app-specific key
            $token = [
                'access_token' => $tokenData['access_token'],
                'expires' => time() + $tokenData['expires_in'],
            ];

            Redis::set($this->redisKeyPrefix, json_encode($token), $tokenData['expires_in']);
            // Redis::expire($this->redisKeyPrefix, $tokenData['expires_in']);

            return $token['access_token'];
        } catch (RequestException $e) {
            throw new ValidationException('Failed to obtain access token: ' . $e->getMessage());
        }
    }

    public function get(string $path, array $params = []): array
    {
        try {
            $accessToken = $this->getValidAccessToken();

            $response = $this->httpClient->get($this->apiBaseUrl . $path, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
                'query' => $params,
            ]);

            return json_decode($response->getBody()->getContents(), true) ?? [];
        } catch (RequestException $e) {
            throw new ValidationException('GET request failed: ' . $e->getMessage());
        }
    }

    public function post(string $path, array $data = [], array $params = []): array
    {
        try {
            $accessToken = $this->getValidAccessToken();

            $response = $this->httpClient->post($this->apiBaseUrl . $path, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
                'json' => $data,
                'query' => $params,
            ]);

            return json_decode($response->getBody()->getContents(), true) ?? [];
        } catch (RequestException $e) {
            throw new ValidationException('POST request failed: ' . $e->getMessage());
        }
    }
}
