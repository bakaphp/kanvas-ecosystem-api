<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Ebay;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use GuzzleHttp\Client as GuzzleClient;
use Kanvas\Connectors\Ebay\Enums\ConfigurationEnum;
use Kanvas\Connectors\Ebay\Enums\CustomFieldEnum;
use Kanvas\Exceptions\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class Client
{
    private const API_SCOPE = 'https://api.ebay.com/oauth/api_scope';

    private const SELL_INVENTORY_SCOPE = 'https://api.ebay.com/oauth/api_scope/sell.inventory';

    private const SELL_ACCOUNT_SCOPE = 'https://api.ebay.com/oauth/api_scope/sell.account';

    private GuzzleClient $httpClient;

    public function __construct(
        private AppInterface $app,
        private CompanyInterface $company,
        ?GuzzleClient $httpClient = null,
    ) {
        $this->httpClient = $httpClient ?? new GuzzleClient(['timeout' => 30]);
    }

    public function authorizationUrl(array $scopes = []): string
    {
        $redirectUri = $this->setting(ConfigurationEnum::EBAY_RU_NAME)
            ?: $this->setting(ConfigurationEnum::EBAY_REDIRECT_URI);

        if ($redirectUri === '') {
            throw new ValidationException('eBay redirect URI or RuName is required');
        }

        return $this->identityBaseUrl() . '/oauth2/authorize?' . http_build_query([
            'client_id' => $this->clientId(),
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'scope' => implode(' ', $scopes === [] ? $this->sellerScopes() : $scopes),
        ]);
    }

    /** @return array<string, mixed> */
    public function exchangeAuthorizationCode(string $code): array
    {
        $result = $this->tokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->setting(ConfigurationEnum::EBAY_RU_NAME)
                ?: $this->setting(ConfigurationEnum::EBAY_REDIRECT_URI),
        ]);

        $this->storeTokenResponse($result);

        return $result;
    }

    /** @return array<string, mixed> */
    public function refreshAccessToken(?string $refreshToken = null, ?string $environment = null): array
    {
        $refreshToken ??= $this->companySetting(CustomFieldEnum::EBAY_REFRESH_TOKEN);

        if ($refreshToken === '') {
            throw new ValidationException('eBay refresh token is not configured');
        }

        $result = $this->tokenRequest(
            [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
                'scope' => implode(' ', $this->sellerScopes()),
            ],
            $environment
        );

        $this->storeTokenResponse($result, $refreshToken);

        return $result;
    }

    public function validateCredentials(
        ?string $clientId = null,
        ?string $clientSecret = null,
        ?string $refreshToken = null,
        ?string $environment = null,
    ): bool {
        try {
            if ($clientId !== null && $clientId !== '' && $clientSecret !== null && $clientSecret !== '') {
                $this->applicationToken($clientId, $clientSecret, $environment);

                return true;
            }

            if (($refreshToken ?? $this->companySetting(CustomFieldEnum::EBAY_REFRESH_TOKEN)) !== '') {
                $this->refreshAccessToken($refreshToken, $environment);

                return true;
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }

    public function resolveAccessToken(): string
    {
        $token = $this->companySetting(CustomFieldEnum::EBAY_ACCESS_TOKEN);
        $expiresAt = $this->timestamp($this->company->get(CustomFieldEnum::EBAY_TOKEN_EXPIRES_AT->value));

        if ($token !== '' && ($expiresAt === 0 || $expiresAt > time() + 60)) {
            return $token;
        }

        $refreshToken = $this->companySetting(CustomFieldEnum::EBAY_REFRESH_TOKEN);
        if ($refreshToken === '') {
            throw new ValidationException('eBay access token expired and no refresh token is configured');
        }

        $refreshExpiresAt = $this->timestamp($this->company->get(CustomFieldEnum::EBAY_REFRESH_TOKEN_EXPIRES_AT->value));
        if ($refreshExpiresAt !== 0 && $refreshExpiresAt <= time()) {
            throw new ValidationException('eBay refresh token has expired; seller authorization is required again');
        }

        $this->refreshAccessToken($refreshToken);

        $token = $this->companySetting(CustomFieldEnum::EBAY_ACCESS_TOKEN);
        if ($token === '') {
            throw new ValidationException('eBay token refresh did not return an access token');
        }

        return $token;
    }

    /** @param array<string, mixed> $inventoryItem
     *  @return array<string, mixed>
     */
    public function createOrReplaceInventoryItem(string $sku, array $inventoryItem): array
    {
        return $this->apiRequest('PUT', '/sell/inventory/v1/inventory_item/' . rawurlencode($sku), $inventoryItem);
    }

    /** @param array<string, mixed> $offer
     *  @return array<string, mixed>
     */
    public function createOffer(array $offer): array
    {
        return $this->apiRequest('POST', '/sell/inventory/v1/offer', $offer);
    }

    /** @param array<string, mixed> $offer
     *  @return array<string, mixed>
     */
    public function updateOffer(string $offerId, array $offer): array
    {
        return $this->apiRequest('PUT', '/sell/inventory/v1/offer/' . rawurlencode($offerId), $offer);
    }

    /** @return array<string, mixed> */
    public function publishOffer(string $offerId): array
    {
        return $this->apiRequest('POST', '/sell/inventory/v1/offer/' . rawurlencode($offerId) . '/publish');
    }

    public function listingUrl(string $listingId): string
    {
        $host = $this->environment() === 'sandbox' ? 'www.sandbox.ebay.com' : 'www.ebay.com';

        return 'https://' . $host . '/itm/' . rawurlencode($listingId);
    }

    /** @return array<string, mixed> */
    public function searchComparableItems(string $query, int $limit = 50): array
    {
        return $this->apiRequest(
            'GET',
            '/buy/browse/v1/item_summary/search',
            query: ['q' => $query, 'limit' => min(max($limit, 1), 200), 'sort' => 'price'],
            useApplicationToken: true,
        );
    }

    /** @return array<string, mixed> */
    private function applicationToken(
        ?string $clientId = null,
        ?string $clientSecret = null,
        ?string $environment = null,
    ): array
    {
        $clientId ??= $this->clientId();
        $clientSecret ??= $this->clientSecret();

        $response = $this->httpClient->post($this->identityBaseUrl($environment) . '/oauth2/token', [
            'auth' => [$clientId, $clientSecret],
            'form_params' => [
                'grant_type' => 'client_credentials',
                'scope' => self::API_SCOPE,
            ],
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
        ]);

        $result = $this->decodeResponse($response);
        if (! is_string($result['access_token'] ?? null) || $result['access_token'] === '') {
            throw new ValidationException('eBay application token response did not include an access token');
        }

        return $result;
    }

    /** @param array<string, string> $form
     *  @return array<string, mixed>
     */
    private function tokenRequest(array $form, ?string $environment = null): array
    {
        $clientId = $this->clientId();
        $clientSecret = $this->clientSecret();

        if ($clientId === '' || $clientSecret === '') {
            throw new ValidationException('eBay client credentials are not configured');
        }

        $response = $this->httpClient->post($this->identityBaseUrl($environment) . '/oauth2/token', [
            'auth' => [$clientId, $clientSecret],
            'form_params' => $form,
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
        ]);

        $result = $this->decodeResponse($response);
        if (! is_string($result['access_token'] ?? null) || $result['access_token'] === '') {
            throw new ValidationException('eBay token response did not include an access token');
        }

        return $result;
    }

    /** @param array<string, mixed> $payload
     *  @param array<string, mixed> $query
     *  @return array<string, mixed>
     */
    private function apiRequest(
        string $method,
        string $path,
        array $payload = [],
        array $query = [],
        bool $useApplicationToken = false,
    ): array {
        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . ($useApplicationToken ? $this->applicationAccessToken() : $this->resolveAccessToken()),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'X-EBAY-C-MARKETPLACE-ID' => 'EBAY_US',
            ],
            'query' => $query,
        ];

        if ($payload !== []) {
            $options['json'] = $payload;
        }

        $response = $this->httpClient->request($method, $this->apiBaseUrl() . $path, $options);

        return $this->decodeResponse($response);
    }

    private function applicationAccessToken(): string
    {
        $token = $this->applicationToken()['access_token'] ?? null;
        if (! is_string($token) || $token === '') {
            throw new ValidationException('eBay did not return an application access token');
        }

        return $token;
    }

    /** @param array<string, mixed> $tokenResponse */
    private function storeTokenResponse(array $tokenResponse, ?string $existingRefreshToken = null): void
    {
        $accessToken = $tokenResponse['access_token'] ?? null;
        if (is_string($accessToken) && $accessToken !== '') {
            $this->company->set(CustomFieldEnum::EBAY_ACCESS_TOKEN->value, $accessToken);
        }

        $expiresIn = $tokenResponse['expires_in'] ?? null;
        if (is_numeric($expiresIn)) {
            $this->company->set(CustomFieldEnum::EBAY_TOKEN_EXPIRES_AT->value, time() + (int) $expiresIn);
        }

        $refreshToken = $tokenResponse['refresh_token'] ?? $existingRefreshToken;
        if (is_string($refreshToken) && $refreshToken !== '') {
            $this->company->set(CustomFieldEnum::EBAY_REFRESH_TOKEN->value, $refreshToken);
        }

        $refreshExpiresIn = $tokenResponse['refresh_token_expires_in'] ?? null;
        if (is_numeric($refreshExpiresIn)) {
            $this->company->set(CustomFieldEnum::EBAY_REFRESH_TOKEN_EXPIRES_AT->value, time() + (int) $refreshExpiresIn);
        }
    }

    /** @return list<string> */
    private function sellerScopes(): array
    {
        return [self::API_SCOPE, self::SELL_INVENTORY_SCOPE, self::SELL_ACCOUNT_SCOPE];
    }

    private function clientId(): string
    {
        return $this->setting(ConfigurationEnum::EBAY_CLIENT_ID);
    }

    private function clientSecret(): string
    {
        return $this->setting(ConfigurationEnum::EBAY_CLIENT_SECRET);
    }

    private function setting(ConfigurationEnum $key): string
    {
        $value = $this->company->get($key->value) ?: $this->app->get($key->value);

        return is_scalar($value) ? (string) $value : '';
    }

    private function companySetting(CustomFieldEnum $key): string
    {
        $value = $this->company->get($key->value);

        return is_scalar($value) ? (string) $value : '';
    }

    private function timestamp(mixed $value): int
    {
        if (is_numeric($value)) {
            return (int) $value;
        }

        if (is_string($value) && $value !== '') {
            $timestamp = strtotime($value);

            return $timestamp === false ? 0 : $timestamp;
        }

        return 0;
    }

    private function environment(?string $environment = null): string
    {
        $configuredEnvironment = $environment ?? $this->setting(ConfigurationEnum::EBAY_ENVIRONMENT);

        return strtolower($configuredEnvironment) === 'sandbox' ? 'sandbox' : 'production';
    }

    private function identityBaseUrl(?string $environment = null): string
    {
        return $this->environment($environment) === 'sandbox'
            ? 'https://auth.sandbox.ebay.com'
            : 'https://auth.ebay.com';
    }

    private function apiBaseUrl(): string
    {
        return $this->environment() === 'sandbox'
            ? 'https://api.sandbox.ebay.com'
            : 'https://api.ebay.com';
    }

    /** @return array<string, mixed> */
    private function decodeResponse(ResponseInterface $response): array
    {
        $contents = (string) $response->getBody();
        if ($contents === '') {
            return [];
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : [];
    }
}
