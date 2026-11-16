<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Microsoft;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Kanvas\Connectors\Microsoft\Enums\ConfigurationEnum;
use Kanvas\Connectors\Microsoft\Support\MicrosoftScopes;
use Kanvas\Connectors\Microsoft\Support\MicrosoftTokenStore;
use Kanvas\Exceptions\ValidationException;

class Client
{
    /**
     * A company's mail connection (Outlook).
     */
    public static function getValidAccessToken(AppInterface $app, CompanyInterface $company): string
    {
        return self::validAccessToken(MicrosoftTokenStore::forCompanyMail($app, $company));
    }

    /**
     * The stored access token, refreshed first when it has expired.
     */
    public static function validAccessToken(MicrosoftTokenStore $store): string
    {
        $accessToken = $store->get(ConfigurationEnum::ACCESS_TOKEN);
        $refreshToken = $store->get(ConfigurationEnum::REFRESH_TOKEN);
        $expiresAt = $store->get(ConfigurationEnum::TOKEN_EXPIRES_AT);

        if ($accessToken === '' || $refreshToken === '') {
            throw new ValidationException('Microsoft OAuth tokens are not configured');
        }

        if ($expiresAt !== '' && Carbon::parse($expiresAt)->isFuture()) {
            return $accessToken;
        }

        $tokenData = self::refreshAccessToken(
            $store->credential(ConfigurationEnum::CLIENT_ID),
            $store->credential(ConfigurationEnum::CLIENT_SECRET),
            $store->credential(ConfigurationEnum::TENANT_ID),
            $refreshToken,
            MicrosoftScopes::forRefresh($store->get(ConfigurationEnum::GRANTED_SCOPES))
        );

        self::storeTokens($store, $tokenData);

        return $tokenData['access_token'];
    }

    /**
     * An empty scope leaves the stored grant as it was: refresh builds its scopes from it.
     *
     * @param array{access_token: string, refresh_token: string, expires_in: int, scope: string} $tokenData
     */
    public static function storeTokens(MicrosoftTokenStore $store, array $tokenData): void
    {
        $store->set(ConfigurationEnum::ACCESS_TOKEN, $tokenData['access_token']);
        $store->set(ConfigurationEnum::REFRESH_TOKEN, $tokenData['refresh_token']);
        $store->set(
            ConfigurationEnum::TOKEN_EXPIRES_AT,
            Carbon::now()->addSeconds($tokenData['expires_in'])->toIso8601String()
        );

        if ($tokenData['scope'] !== '') {
            $store->set(ConfigurationEnum::GRANTED_SCOPES, $tokenData['scope']);
        }
    }

    /**
     * Exchange an authorization code for access and refresh tokens.
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int, scope: string}
     */
    public static function exchangeCodeForToken(
        string $clientId,
        string $clientSecret,
        string $tenantId,
        string $redirectUri,
        string $code,
        string $scopes
    ): array {
        $data = self::requestToken($tenantId, [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
            'scope' => $scopes,
        ], 'Failed to exchange code for Microsoft access token');

        return [
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? '',
            'expires_in' => $data['expires_in'] ?? 3600,
            'scope' => $data['scope'] ?? '',
        ];
    }

    /**
     * Refresh an access token using a refresh token.
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int, scope: string}
     */
    public static function refreshAccessToken(
        string $clientId,
        string $clientSecret,
        string $tenantId,
        string $refreshToken,
        string $scopes
    ): array {
        $data = self::requestToken($tenantId, [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
            'scope' => $scopes,
        ], 'Failed to refresh Microsoft access token');

        return [
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? $refreshToken,
            'expires_in' => $data['expires_in'] ?? 3600,
            'scope' => $data['scope'] ?? '',
        ];
    }

    /**
     * @param array<string, string> $form
     *
     * @return array{access_token: string, refresh_token?: string, expires_in?: int, scope?: string}
     */
    private static function requestToken(string $tenantId, array $form, string $failureMessage): array
    {
        $response = Http::asForm()
            ->throw()
            ->post("https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token", $form);

        /** @var array{access_token?: string, refresh_token?: string, expires_in?: int, scope?: string} $data */
        $data = $response->json() ?? [];

        if (! isset($data['access_token']) || $data['access_token'] === '') {
            throw new ValidationException($failureMessage);
        }

        return $data;
    }

    /**
     * Get the authenticated user's profile.
     *
     * @return array{id: string, displayName: string, mail: string, userPrincipalName: string}
     */
    public static function getUserProfile(string $accessToken): array
    {
        /** @var array{id: string, displayName: string, mail: string, userPrincipalName: string} $data */
        $data = Http::withToken($accessToken)
            ->acceptJson()
            ->throw()
            ->get('https://graph.microsoft.com/v1.0/me')
            ->json() ?? [];

        return $data;
    }

    /**
     * List mail messages from the authenticated user's mailbox.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listMessages(
        string $accessToken,
        int $top = 25,
        int $skip = 0,
        ?string $filter = null
    ): array {
        $client = new GuzzleClient();

        $query = [
            '$top' => $top,
            '$skip' => $skip,
            '$orderby' => 'receivedDateTime desc',
            '$select' => 'id,subject,from,toRecipients,receivedDateTime,isRead,bodyPreview,hasAttachments',
        ];

        if ($filter !== null) {
            $query['$filter'] = $filter;
        }

        $response = $client->get('https://graph.microsoft.com/v1.0/me/messages', [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
                'Accept' => 'application/json',
            ],
            'query' => $query,
        ]);

        /** @var array{value?: list<array<string, mixed>>} $data */
        $data = json_decode($response->getBody()->getContents(), true);

        return $data['value'] ?? [];
    }

    /**
     * Get a specific mail message.
     *
     * @return array<string, mixed>
     */
    public static function getMessage(string $accessToken, string $messageId): array
    {
        $client = new GuzzleClient();

        $response = $client->get("https://graph.microsoft.com/v1.0/me/messages/{$messageId}", [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
                'Accept' => 'application/json',
            ],
        ]);

        /** @var array<string, mixed> $data */
        $data = json_decode($response->getBody()->getContents(), true);

        return $data;
    }

    /**
     * Send an email via Microsoft Graph.
     *
     * @param array<int, string> $toRecipients
     * @param array<int, string> $ccRecipients
     */
    public static function sendMail(
        string $accessToken,
        array $toRecipients,
        string $subject,
        string $body,
        string $contentType = 'HTML',
        array $ccRecipients = [],
        bool $saveToSentItems = true
    ): void {
        $client = new GuzzleClient();

        $toArray = array_map(fn (string $email) => [
            'emailAddress' => ['address' => $email],
        ], $toRecipients);

        $ccArray = array_map(fn (string $email) => [
            'emailAddress' => ['address' => $email],
        ], $ccRecipients);

        $payload = [
            'message' => [
                'subject' => $subject,
                'body' => [
                    'contentType' => $contentType,
                    'content' => $body,
                ],
                'toRecipients' => $toArray,
            ],
            'saveToSentItems' => $saveToSentItems,
        ];

        if (! empty($ccArray)) {
            $payload['message']['ccRecipients'] = $ccArray;
        }

        $client->post('https://graph.microsoft.com/v1.0/me/sendMail', [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'json' => $payload,
        ]);
    }

    /**
     * Reply to a mail message.
     */
    public static function replyToMessage(
        string $accessToken,
        string $messageId,
        string $comment,
        string $contentType = 'HTML'
    ): void {
        $client = new GuzzleClient();

        $client->post("https://graph.microsoft.com/v1.0/me/messages/{$messageId}/reply", [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'json' => [
                'comment' => $comment,
            ],
        ]);
    }
}
