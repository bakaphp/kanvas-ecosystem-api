<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Alanube\Enums;

/**
 * Company settings, except the MAIN_TOKEN_* pair, which is app level: in `kanvas` mode every tenant issues
 * through our own Alanube main company, so the token is the app's, never the tenant's.
 *
 * The environment is not here: it is Scribe's `scribe-ecf-environment`, shared by every e-CF provider.
 */
enum ConfigurationEnum: string
{
    case ACCOUNT_MODE = 'alanube-account-mode';

    // Own mode only, stored encrypted.
    case API_TOKEN = 'alanube-api-token';

    // The Alanube company id (ULID). In kanvas mode it is the associated company every call is scoped to.
    case COMPANY_ID = 'alanube-company-id';

    // The DGII sender block (RNC, legal name, address) Alanube needs on every document, read from Alanube's
    // own company record at connect time so it can never disagree with the certificate's RNC.
    case SENDER_PROFILE = 'alanube-sender-profile';

    // Encrypted. The previous secret stays valid until its expiry so a rotation drops no delivery.
    case WEBHOOK_SECRET = 'alanube-webhook-secret';
    case WEBHOOK_PREVIOUS_SECRET = 'alanube-webhook-previous-secret';
    case WEBHOOK_PREVIOUS_SECRET_EXPIRES_AT = 'alanube-webhook-previous-secret-expires-at';

    case GOVERNMENT_STATUS = 'alanube-government-status';
    case SET_TEST_ID = 'alanube-set-test-id';

    // Company settings are not per app; webhook-less paths (setup, readiness) need to know which app owns the books.
    case APP_ID = 'alanube-app-id';

    case MAIN_TOKEN_TEST = 'alanube-main-token-test';
    case MAIN_TOKEN_PRODUCTION = 'alanube-main-token-production';

    public const string SANDBOX_URL = 'https://sandbox.alanube.co/dom/v1/';
    public const string PRODUCTION_URL = 'https://api.alanube.co/dom/v1/';
    public const string WEBHOOK_SECRET_HEADER = 'x-kanvas-webhook-secret';

    /**
     * @return list<string>
     */
    public static function companyKeys(): array
    {
        return array_values(array_map(
            fn (self $case): string => $case->value,
            array_filter(self::cases(), fn (self $case): bool => ! $case->isAppLevel()),
        ));
    }

    public function isAppLevel(): bool
    {
        return $this === self::MAIN_TOKEN_TEST || $this === self::MAIN_TOKEN_PRODUCTION;
    }
}
