<?php

declare(strict_types=1);

namespace Kanvas\Communication\Channels\Email\Providers\Mailgun\Enums;

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
}
