<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun\Webhooks;

use Kanvas\Communication\Channels\Email\Providers\Mailgun\Webhooks\EmailDeliveryWebhookJob as CurrentEmailDeliveryWebhookJob;

/**
 * @deprecated moved to {@see CurrentEmailDeliveryWebhookJob}. Receivers saved before the move name this class in `actions.model_name`, and webhook calls already queued carry it.
 */
class EmailDeliveryWebhookJob extends CurrentEmailDeliveryWebhookJob
{
}
