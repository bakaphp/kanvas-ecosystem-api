<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mercury\Enums;

enum ConfigurationEnum: string
{
    case API_TOKEN = 'MERCURY_API_TOKEN';
    case BASE_URL = 'MERCURY_BASE_URL';
    case SYNC_ENABLED = 'MERCURY_SYNC_ENABLED';
    case SYNC_APP_ID = 'MERCURY_SYNC_APP_ID';
    case SYNC_CURSOR = 'MERCURY_SYNC_CURSOR';
    case WEBHOOK_ID = 'MERCURY_WEBHOOK_ID';
    case FORCE_VERIFY_WEBHOOK_SIGNATURE = 'MERCURY_FORCE_VERIFY_WEBHOOK_SIGNATURE';
    case WEBHOOK_SECRET = 'MERCURY_WEBHOOK_SECRET';
    case AR_DEPOSIT_ACCOUNT_ID = 'MERCURY_AR_DEPOSIT_ACCOUNT_ID';
    case AR_SEND_EMAIL = 'MERCURY_AR_SEND_EMAIL';

    public const string DEFAULT_BASE_URL = 'https://api.mercury.com/api/v1';

    public function forAccount(string $mercuryAccountId): string
    {
        return $this->value . '-' . $mercuryAccountId;
    }
}
