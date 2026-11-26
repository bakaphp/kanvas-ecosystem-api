<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun\Enums;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;

enum ConfigurationEnum: string
{
    case WEBHOOK_SIGNING_KEY = 'MAILGUN_WEBHOOK_SIGNING_KEY';
    case API_KEY = 'MAILGUN_API_KEY';
    case LAST_VALIDATED_AT = 'MAILGUN_LAST_VALIDATED_AT';

    // Sending/receiving domain the company's agent mailboxes live on (e.g. `agents.acme.com`).
    // Company-level first, app-level as the fallback for single-domain installs.
    case DOMAIN = 'MAILGUN_DOMAIN';

    // The address the company communicates from on that domain: what its email connection sends
    // as and what the Mailgun route forwards inbound for. Company-level; the company's own from
    // email stands in when it is on the domain.
    case FROM_ADDRESS = 'MAILGUN_FROM_ADDRESS';

    /**
     * The key Mailgun's webhooks are checked against, '' when none is stored: the company's own,
     * and the app's for a company that has none because it sends through the app's Mailgun.
     */
    public static function signingKeyFor(AppInterface $app, CompanyInterface $company): string
    {
        $companyKey = (string) $company->get(self::WEBHOOK_SIGNING_KEY->value);

        return $companyKey !== '' ? $companyKey : (string) $app->get(self::WEBHOOK_SIGNING_KEY->value);
    }
}
