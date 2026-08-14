<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Gmail;

use Baka\Contracts\AppInterface;
use Google\Client as GoogleApiClient;
use Google\Service\Gmail as GmailService;
use Kanvas\Connectors\Gmail\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Throwable;

/** Thin factory for an authenticated Gmail API service, scoped to the app's configured OAuth refresh token. */
class Client
{
    public static function getInstance(AppInterface $app): GmailService
    {
        $clientId = $app->get(ConfigurationEnum::CLIENT_ID->value);
        $clientSecret = $app->get(ConfigurationEnum::CLIENT_SECRET->value);
        $refreshToken = $app->get(ConfigurationEnum::REFRESH_TOKEN->value);

        if (empty($clientId) || empty($clientSecret) || empty($refreshToken)) {
            throw new ValidationException(
                'Gmail is not configured for this app — set gmail-client-id, gmail-client-secret, and '
                    . 'gmail-refresh-token.'
            );
        }

        try {
            $googleClient = new GoogleApiClient();
            $googleClient->setClientId((string) $clientId);
            $googleClient->setClientSecret((string) $clientSecret);
            $googleClient->addScope(GmailService::GMAIL_MODIFY);
            $googleClient->fetchAccessTokenWithRefreshToken((string) $refreshToken);

            return new GmailService($googleClient);
        } catch (Throwable $e) {
            throw new ValidationException('Could not authenticate with Gmail: ' . $e->getMessage());
        }
    }
}
