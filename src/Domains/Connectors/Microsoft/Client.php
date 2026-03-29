<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Microsoft;

use GuzzleHttp\Client as GuzzleClient;
use Kanvas\Exceptions\ValidationException;

class Client
{
    /**
     * Exchange an authorization code for access and refresh tokens.
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int}
     */
    public static function exchangeCodeForToken(
        string $clientId,
        string $clientSecret,
        string $tenantId,
        string $redirectUri,
        string $code
    ): array {
        $client = new GuzzleClient();

        $response = $client->post("https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token", [
            'form_params' => [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'code' => $code,
                'redirect_uri' => $redirectUri,
                'grant_type' => 'authorization_code',
                'scope' => 'offline_access Mail.Read Mail.Send Mail.ReadWrite',
            ],
        ]);

        /** @var array{access_token?: string, refresh_token?: string, expires_in?: int} $data */
        $data = json_decode($response->getBody()->getContents(), true);

        if (! isset($data['access_token']) || $data['access_token'] === '') {
            throw new ValidationException('Failed to exchange code for Microsoft access token');
        }

        return [
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? '',
            'expires_in' => $data['expires_in'] ?? 3600,
        ];
    }

    /**
     * Refresh an access token using a refresh token.
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int}
     */
    public static function refreshAccessToken(
        string $clientId,
        string $clientSecret,
        string $tenantId,
        string $refreshToken
    ): array {
        $client = new GuzzleClient();

        $response = $client->post("https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token", [
            'form_params' => [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token',
                'scope' => 'offline_access Mail.Read Mail.Send Mail.ReadWrite',
            ],
        ]);

        /** @var array{access_token?: string, refresh_token?: string, expires_in?: int} $data */
        $data = json_decode($response->getBody()->getContents(), true);

        if (! isset($data['access_token']) || $data['access_token'] === '') {
            throw new ValidationException('Failed to refresh Microsoft access token');
        }

        return [
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? $refreshToken,
            'expires_in' => $data['expires_in'] ?? 3600,
        ];
    }

    /**
     * Get the authenticated user's profile.
     *
     * @return array{id: string, displayName: string, mail: string, userPrincipalName: string}
     */
    public static function getUserProfile(string $accessToken): array
    {
        $client = new GuzzleClient();

        $response = $client->get('https://graph.microsoft.com/v1.0/me', [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
                'Accept' => 'application/json',
            ],
        ]);

        /** @var array{id: string, displayName: string, mail: string, userPrincipalName: string} $data */
        $data = json_decode($response->getBody()->getContents(), true);

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
