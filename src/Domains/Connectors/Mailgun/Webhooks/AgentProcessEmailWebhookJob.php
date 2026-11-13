<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun\Webhooks;

use Kanvas\Communication\Channels\Email\Providers\Mailgun\Webhooks\AgentProcessEmailWebhookJob as CurrentAgentProcessEmailWebhookJob;

/**
 * @deprecated moved to {@see CurrentAgentProcessEmailWebhookJob}. Receivers saved before the move name this class in `actions.model_name`, and webhook calls already queued carry it.
 */
class AgentProcessEmailWebhookJob extends CurrentAgentProcessEmailWebhookJob
{
}
