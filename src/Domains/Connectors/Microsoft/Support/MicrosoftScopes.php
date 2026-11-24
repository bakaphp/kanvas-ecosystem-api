<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Microsoft\Support;

use Baka\Contracts\AppInterface;
use Kanvas\Connectors\Microsoft\Enums\ConfigurationEnum;

class MicrosoftScopes
{
    public const string MAIL = 'offline_access Mail.Read Mail.Send Mail.ReadWrite';

    public const string FILES = 'Files.ReadWrite';

    /**
     * The OAuth callback reads GET /me to show who connected; without it a consumer account
     * answers 403 after the tokens are already stored.
     */
    public const string PROFILE = 'User.Read';

    /**
     * The app's shared OneDrive connection: files only, no mail.
     */
    public const string ONEDRIVE = 'offline_access Files.ReadWrite User.Read';

    /**
     * A company's connection: its mail and its own OneDrive share one consent and one set of tokens.
     */
    public static function forAuthorization(AppInterface $app): string
    {
        $configured = (string) ($app->get(ConfigurationEnum::SCOPES->value) ?? self::MAIL);

        return self::merge($configured, self::FILES . ' ' . self::PROFILE);
    }

    public static function grantsFiles(string $granted): bool
    {
        return in_array(strtolower(self::FILES), array_map('strtolower', preg_split('/\s+/', $granted) ?: []), true);
    }

    /**
     * Microsoft rejects a refresh asking for scopes the user never consented to, so a company
     * connected before the granted scopes were stored keeps refreshing with mail scopes only.
     */
    public static function forRefresh(?string $granted): string
    {
        $granted = trim((string) $granted);

        if ($granted === '') {
            return self::MAIL;
        }

        return self::merge($granted, 'offline_access');
    }

    private static function merge(string $scopes, string $extra): string
    {
        $merged = [];

        foreach (preg_split('/\s+/', trim($scopes . ' ' . $extra), -1, PREG_SPLIT_NO_EMPTY) as $scope) {
            $merged[strtolower($scope)] ??= $scope;
        }

        return implode(' ', $merged);
    }
}
