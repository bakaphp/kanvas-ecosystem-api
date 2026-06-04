<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Hermes\Services;

use Kanvas\Exceptions\ValidationException;

class GoogleCredentialFileBuilderService
{
    public const string TOKEN_FILENAME = 'google_token.json';
    public const string CLIENT_SECRET_FILENAME = 'google_client_secret.json';

    private const string TOKEN_URI = 'https://oauth2.googleapis.com/token';
    private const string AUTH_URI = 'https://accounts.google.com/o/oauth2/auth';

    /**
     * Build the on-disk credential files Hermes' Google Workspace tools expect,
     * from whatever the frontend persisted on the agent's integration_google
     * custom field. The frontend owns the OAuth dance, so we pass-through its
     * payload — no scope defaulting, no token refresh here.
     *
     * @param array<string, mixed> $config
     * @return array<string, string> filename => JSON content
     */
    public static function buildFiles(array $config): array
    {
        $clientId = self::requireString($config, 'client_id');
        $clientSecret = self::requireString($config, 'client_secret');
        $refreshToken = self::requireString($config, 'refresh_token');

        $token = [
            'token' => $config['access_token'] ?? null,
            'refresh_token' => $refreshToken,
            'token_uri' => self::TOKEN_URI,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'scopes' => self::normalizeScopes($config['scopes'] ?? []),
            'expiry' => $config['expiry'] ?? null,
        ];

        $clientSecretFile = [
            'installed' => [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'auth_uri' => self::AUTH_URI,
                'token_uri' => self::TOKEN_URI,
                'redirect_uris' => ['http://localhost'],
            ],
        ];

        return [
            self::TOKEN_FILENAME => self::encode($token),
            self::CLIENT_SECRET_FILENAME => self::encode($clientSecretFile),
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function requireString(array $config, string $key): string
    {
        $value = $config[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new ValidationException(
                "Google integration config is missing required field: {$key}"
            );
        }

        return $value;
    }

    /**
     * @return array<int, string>
     */
    private static function normalizeScopes(mixed $scopes): array
    {
        if (! is_array($scopes)) {
            return [];
        }

        $normalized = [];
        foreach ($scopes as $scope) {
            if (is_string($scope) && $scope !== '') {
                $normalized[] = $scope;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function encode(array $payload): string
    {
        return (string) json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        );
    }
}
