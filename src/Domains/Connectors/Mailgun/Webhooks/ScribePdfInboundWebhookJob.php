<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun\Webhooks;

use Kanvas\Communication\Channels\Email\Providers\Mailgun\Webhooks\ScribePdfInboundWebhookJob as CurrentScribePdfInboundWebhookJob;

/**
 * @deprecated moved to {@see CurrentScribePdfInboundWebhookJob}. Receivers saved before the move name this class in `actions.model_name`, and webhook calls already queued carry it.
 */
class ScribePdfInboundWebhookJob extends CurrentScribePdfInboundWebhookJob
{
}
