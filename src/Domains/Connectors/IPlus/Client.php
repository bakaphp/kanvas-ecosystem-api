<?php

declare(strict_types=1);

namespace Kanvas\Connectors\IPlus;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Redis;
use Kanvas\Connectors\IPlus\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Token\AccessTokenInterface;

class Client
{
    protected string $baseUrl;
    protected GenericProvider $provider;
    protected ?AccessTokenInterface $accessToken = null;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company
    ) {
        $this->baseUrl = $this->app->get(ConfigurationEnum::BASE_URL->value);
        $clientId = $this->app->get(ConfigurationEnum::CLIENT_ID->value);
        $clientSecret = $this->app->get(ConfigurationEnum::CLIENT_SECRET->value);

        if (empty($clientId) || empty($clientSecret)) {
            throw new ValidationException('IPlus keys are not set for ' . $this->app->name);
        }

        $this->provider = new GenericProvider([
            'clientId' => $clientId,
            'clientSecret' => $clientSecret,
            'urlAuthorize' => $this->baseUrl . '/oauth2/authorize',
            'urlAccessToken' => $this->baseUrl . '/oauth2/token',
            'urlResourceOwnerDetails' => '',
            'scopes' => [], // Add your required scopes here
        ]);
    }

    public function getValidAccessToken(): AccessTokenInterface
    {
        // Try to get token from Redis
        $cachedToken = Redis::get('iplus_access_token');
        if ($cachedToken) {
            $tokenData = json_decode($cachedToken, true);
            if ($tokenData && $this->isTokenValid($tokenData)) {
                return $this->provider->getAccessToken('refresh_token', [
                    'refresh_token' => $tokenData['refresh_token'],
                ]);
            }
        }

        // If no valid cached token, get a new one
        return $this->requestNewAccessToken();
    }

    public function isTokenValid(array $tokenData): bool
    {
        return isset($tokenData['expires']) && $tokenData['expires'] > time();
    }

    public function requestNewAccessToken(): AccessTokenInterface
    {
        try {
            $accessToken = $this->provider->getAccessToken('client_credentials');

            // Cache the token
            $tokenData = [
                'access_token' => $accessToken->getToken(),
                'refresh_token' => $accessToken->getRefreshToken(),
                'expires' => $accessToken->getExpires(),
            ];

            Redis::set('iplus_access_token', json_encode($tokenData));
            Redis::expire('iplus_access_token', $accessToken->getExpires() - time());

            return $accessToken;
        } catch (\League\OAuth2\Client\Provider\Exception\IdentityProviderException $e) {
            throw new ValidationException('Failed to obtain access token: ' . $e->getMessage());
        }
    }

    public function get(string $path, array $params = []): array
    {
        try {
            $token = $this->getValidAccessToken();

            $request = $this->provider->getAuthenticatedRequest(
                'GET',
                $this->baseUrl . $path . ($params ? '?' . http_build_query($params) : ''),
                $token
            );

            $response = $this->provider->getHttpClient()->send($request);

            return json_decode($response->getBody()->getContents(), true) ?? [];
        } catch (RequestException $e) {
            throw new ValidationException('GET request failed: ' . $e->getMessage());
        }
    }

    public function post(string $path, array $data = [], array $params = []): array
    {
        try {
            $token = $this->getValidAccessToken();

            $request = $this->provider->getAuthenticatedRequest(
                'POST',
                $this->baseUrl . $path . ($params ? '?' . http_build_query($params) : ''),
                $token,
                ['body' => json_encode($data)]
            );

            $response = $this->provider->getHttpClient()->send($request);

            return json_decode($response->getBody()->getContents(), true) ?? [];
        } catch (RequestException $e) {
            throw new ValidationException('POST request failed: ' . $e->getMessage());
        }
    }
}
