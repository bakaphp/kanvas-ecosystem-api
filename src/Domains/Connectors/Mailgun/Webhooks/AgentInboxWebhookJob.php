<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun\Webhooks;

use Kanvas\Communication\Channels\Email\Providers\Mailgun\Webhooks\AgentInboxWebhookJob as CurrentAgentInboxWebhookJob;

/**
 * @deprecated moved to {@see CurrentAgentInboxWebhookJob}. Receivers saved before the move name this class in `actions.model_name`, and webhook calls already queued carry it.
 */
class AgentInboxWebhookJob extends CurrentAgentInboxWebhookJob
{
}
