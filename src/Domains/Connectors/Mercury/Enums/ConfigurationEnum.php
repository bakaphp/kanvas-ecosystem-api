<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mercury\Enums;

enum ConfigurationEnum: string
{
    /** Company-level, not app-level: one Kanvas app serves many tenants, each with its own Mercury org. */
    case API_TOKEN = 'MERCURY_API_TOKEN';

    case BASE_URL = 'MERCURY_BASE_URL';

    case SYNC_ENABLED = 'MERCURY_SYNC_ENABLED';

    /** The nightly pull iterates companies with no request context to infer the app from. */
    case SYNC_APP_ID = 'MERCURY_SYNC_APP_ID';

    /** Per Mercury account: the postedAt we last ingested through. */
    case SYNC_CURSOR = 'MERCURY_SYNC_CURSOR';

    case WEBHOOK_ID = 'MERCURY_WEBHOOK_ID';

    /**
     * Mercury returns this EXACTLY ONCE, in the response to POST /webhooks — never on GET, never on update.
     * Miss it and the only way back is to delete the webhook and register a new one.
     */
    case WEBHOOK_SECRET = 'MERCURY_WEBHOOK_SECRET';

    /** Mercury requires a destinationAccountId on every invoice; unset, we fall back to checking. */
    case AR_DEPOSIT_ACCOUNT_ID = 'MERCURY_AR_DEPOSIT_ACCOUNT_ID';

    /** Off by default — flipping the connector on must not email real invoices to real customers. */
    case AR_SEND_EMAIL = 'MERCURY_AR_SEND_EMAIL';

    public const string DEFAULT_BASE_URL = 'https://api.mercury.com/api/v1';

    public function forAccount(string $mercuryAccountId): string
    {
        return $this->value . '-' . $mercuryAccountId;
    }
}
