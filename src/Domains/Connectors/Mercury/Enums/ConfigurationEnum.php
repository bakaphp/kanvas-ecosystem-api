<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mercury\Enums;

enum ConfigurationEnum: string
{
    /**
     * Mercury API token. Company-level, not app-level: one Kanvas app serves many tenants and each holds
     * its own Mercury organization. A read-only token is enough — the connector never moves money.
     */
    case API_TOKEN = 'MERCURY_API_TOKEN';

    case BASE_URL = 'MERCURY_BASE_URL';

    case SYNC_ENABLED = 'MERCURY_SYNC_ENABLED';

    /** Cursor per Mercury account: the postedAt we last ingested through. */
    case SYNC_CURSOR = 'MERCURY_SYNC_CURSOR';

    /** Set in PR 4 when the webhook is registered, so we can tear it down on disconnect. */
    case WEBHOOK_ID = 'MERCURY_WEBHOOK_ID';

    /**
     * Which Mercury account collects payments for AR invoices we push. Mercury requires a
     * `destinationAccountId` on every invoice; without this set we fall back to the tenant's checking
     * account, which is what a company with one account expects anyway.
     */
    case AR_DEPOSIT_ACCOUNT_ID = 'MERCURY_AR_DEPOSIT_ACCOUNT_ID';

    /**
     * Whether pushing invoices to Mercury also EMAILS them to the customer. Off by default — sending a real
     * invoice to a real customer is not something a sync should do behind your back the first time you flip
     * the connector on.
     */
    case AR_SEND_EMAIL = 'MERCURY_AR_SEND_EMAIL';

    public const string DEFAULT_BASE_URL = 'https://api.mercury.com/api/v1';

    /**
     * Cursor is per (company, mercury account) — a tenant can hold several accounts and they backfill
     * independently.
     */
    public function forAccount(string $mercuryAccountId): string
    {
        return $this->value . '-' . $mercuryAccountId;
    }
}
