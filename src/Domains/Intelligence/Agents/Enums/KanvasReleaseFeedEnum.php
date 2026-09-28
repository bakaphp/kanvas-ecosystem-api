<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Enums;

enum KanvasReleaseFeedEnum: string
{
    /**
     * Comma-separated `owner/repo` list of KANVAS's own repositories. Separate from the connector's
     * generic github_token so a tenant connecting their own GitHub never widens what the customer
     * update agent is allowed to read.
     */
    case REPOSITORIES = 'kanvas_release_repositories';

    /**
     * The monthly cron's opt-in. Off unless an app sets it, because having accounts tagged
     * `newsletter` is not consent to mail them on a schedule — a tag is how a CSM organises records,
     * and the same word could already be in use on an app that has never heard of this feature.
     * Turning it on is a deliberate act by that app's operator.
     */
    case MONTHLY_UPDATE_ENABLED = 'kanvas_customer_update_monthly_enabled';

    /**
     * Where a customer's reply goes. A newsletter that answers to `noreply@` is the broken kind, but
     * the From address is pinned to whatever domain is verified for sending — a From nobody has
     * authorized fails DMARC and lands in spam. Reply-To carries no such constraint, so it is the one
     * lever that can point at a real person today, before any mailbox exists to send from.
     *
     * Company setting wins over app: one app can serve several tenants, and the human who answers is
     * theirs, not the platform's.
     */
    case REPLY_TO = 'kanvas_customer_update_reply_to';
}
