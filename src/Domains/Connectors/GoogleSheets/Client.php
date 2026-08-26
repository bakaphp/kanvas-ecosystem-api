<?php

declare(strict_types=1);

namespace Kanvas\Connectors\GoogleSheets;

use Baka\Contracts\AppInterface;
use Google\Client as GoogleApiClient;
use Google\Service\Sheets as GoogleSheetsService;
use Kanvas\Connectors\GoogleSheets\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Throwable;

/** Thin factory for an authenticated Google Sheets API service, scoped to the app's configured service account. */
class Client
{
    public static function getInstance(AppInterface $app): GoogleSheetsService
    {
        $raw = $app->get(ConfigurationEnum::GOOGLE_SHEETS_CREDENTIALS->value);

        // The custom-fields store round-trips a JSON string back as an already-decoded array —
        // accept either shape rather than assuming set() always comes back as the string it was given.
        $decoded = match (true) {
            is_array($raw) => $raw,
            is_string($raw) && $raw !== '' => json_decode($raw, true),
            default => null,
        };

        if ($decoded === null) {
            throw new ValidationException(
                'Google Sheets is not configured for this app — set GOOGLE_SHEETS_CREDENTIALS to a service-account JSON key.'
            );
        }

        if (! is_array($decoded)) {
            throw new ValidationException('Google Sheets credentials are not valid JSON.');
        }

        try {
            $googleClient = new GoogleApiClient();
            $googleClient->setAuthConfig($decoded);
            $googleClient->addScope(GoogleSheetsService::SPREADSHEETS);

            $impersonateUser = trim((string) ($app->get(ConfigurationEnum::IMPERSONATE_USER->value) ?? ''));
            if ($impersonateUser !== '') {
                $googleClient->setSubject($impersonateUser);
            }

            return new GoogleSheetsService($googleClient);
        } catch (Throwable $e) {
            throw new ValidationException('Could not authenticate with Google Sheets: ' . $e->getMessage());
        }
    }
}
